<?php

namespace App\service;

use App\Entity\Action;
use App\Entity\Pnj;
use App\Entity\Quete;
use App\Entity\Sequence;
use App\Entity\User;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Enum\TypeItem;
use App\Exception\UnsupportedQuestActionException;
use App\Repository\CarteCarreauRepository;
use App\Repository\EvenementJeuRepository;
use App\Repository\QueteRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserQueteRepository;

/**
 * Journal de quêtes du joueur — LECTURE PURE (aucune écriture, aucun flush).
 *
 * Répond à trois questions, dans cet ordre de priorité : « qu'est-ce que je dois faire
 * maintenant, et OÙ ? » (quêtes en cours), « où trouver du travail ? » (disponibles),
 * « qu'ai-je déjà accompli ? » (terminées).
 *
 * Rien n'est décidé ici : la progression d'un objectif compté vient de
 * `QuestProgressionService::buildProgress`, l'état d'une condition de
 * `isActionConditionMet`, les prérequis de `checkPrerequisites`, l'aperçu des récompenses
 * de `RecompenseService::apercu`. Le journal ne fait que les ranger. S'il calculait
 * lui-même, il finirait par annoncer « 3 / 5 » quand le PNJ en compte 4.
 *
 * Deux lectures du contenu à connaître :
 *  - les actions À CONDITION d'une étape sont des ALTERNATIVES (chacune est un bouton, une
 *    seule suffit à avancer) — d'où `objectifsAuChoix` quand il y en a plusieurs ;
 *  - une quête peut BRANCHER (`Action.nextSequence`) : « étape 2 sur 3 » serait un
 *    mensonge sur une quête dont un choix saute directement à la fin. On annonce donc le
 *    numéro de l'étape et le nombre d'étapes franchies, jamais un total.
 */
class QuestJournalService
{
    public function __construct(
        private readonly UserQueteRepository $userQueteRepository,
        private readonly QueteRepository $queteRepository,
        private readonly SequenceRepository $sequenceRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly EvenementJeuRepository $evenementJeuRepository,
        private readonly QuestProgressionService $questProgressionService,
        private readonly RecompenseService $recompenseService,
        private readonly SacService $sacService,
    ) {}

    /**
     * @return array{enCours: list<array>, disponibles: list<array>, terminees: list<array>}
     */
    public function journal(User $user): array
    {
        $userQuetes = $this->userQueteRepository->findBy(['user' => $user], ['id' => 'DESC']);
        $connues = [];
        $enCours = [];
        $terminees = [];
        $dates = $this->evenementJeuRepository->datesQuetesTerminees((int)$user->getId());

        foreach ($userQuetes as $userQuete) {
            $quete = $userQuete->getQuete();
            $connues[$quete->getId()] = true;

            if ($userQuete->getIsDone()) {
                $terminees[] = $this->fiche($quete, $user, 'terminee') + [
                    'termineeLe' => isset($dates[$quete->getId()])
                        ? (new \DateTimeImmutable($dates[$quete->getId()]))->format(\DATE_ATOM)
                        : null,
                ];
                continue;
            }

            $enCours[] = $this->fiche($quete, $user, 'enCours') + [
                'etape' => $this->etape($userQuete->getSequence(), $user, $userQuete),
            ];
        }

        // Les plus récemment finies en tête ; celles sans date (antérieures au journal)
        // gardent l'ordre de démarrage, derrière.
        usort($terminees, static fn (array $a, array $b) => strcmp((string)$b['termineeLe'], (string)$a['termineeLe']));

        return [
            'enCours' => $enCours,
            'disponibles' => $this->disponibles($user, $connues),
            'terminees' => $terminees,
        ];
    }

    /**
     * Quêtes qu'un joueur pourrait démarrer MAINTENANT : jamais commencées, prérequis
     * remplis, avec une première étape, et dont le donneur est posé sur une carte.
     *
     * Les quêtes verrouillées ne sont PAS listées : le journal deviendrait une liste de
     * choses impossibles, et révélerait du contenu que le joueur n'a aucun moyen de voir.
     * Un donneur absent de toute carte (PNJ de test, PNJ retiré) rendrait la quête
     * introuvable : l'annoncer serait envoyer le joueur nulle part.
     *
     * @param array<int, true> $connues
     */
    private function disponibles(User $user, array $connues): array
    {
        $disponibles = [];
        foreach ($this->queteRepository->findBy([], ['id' => 'ASC']) as $quete) {
            if (isset($connues[$quete->getId()])) {
                continue;
            }

            $fiche = $this->fiche($quete, $user, 'disponible');
            if ($fiche['donneur'] === null || $fiche['donneur']['lieu'] === null) {
                continue;
            }

            if ($this->sequenceRepository->findOneBy(['quete' => $quete, 'position' => 1]) === null) {
                continue;
            }

            if ($this->questProgressionService->checkPrerequisites($user, $quete) !== []) {
                continue;
            }

            $disponibles[] = $fiche;
        }

        return $disponibles;
    }

    private function fiche(Quete $quete, User $user, string $statut): array
    {
        $donneur = null;
        foreach ($quete->getPnjs() as $pnj) {
            $candidat = $this->pnj($pnj, $user);
            // Un donneur posé l'emporte sur un donneur absent des cartes.
            if ($donneur === null || ($donneur['lieu'] === null && $candidat['lieu'] !== null)) {
                $donneur = $candidat;
            }
        }

        return [
            'id' => $quete->getId(),
            'nom' => (string)$quete->getName(),
            'statut' => $statut,
            'introduction' => $this->paragraphes($quete->getIntroduction()),
            'niveauMin' => $quete->getMinimalLevel() > 0 ? (int)$quete->getMinimalLevel() : null,
            'donneur' => $donneur,
        ];
    }

    private function etape(?Sequence $sequence, User $user, UserQuete $userQuete): ?array
    {
        if ($sequence === null) {
            return null;
        }

        $objectifs = [];
        $decision = false;
        $jeuxDeRecompenses = [];

        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            $action = $sequenceAction->getAction();
            $type = $action->getActionType();
            $recompenses = $this->recompenseService->apercu($action->getRecompense());
            if ($recompenses !== []) {
                $jeuxDeRecompenses[json_encode($recompenses)] = $recompenses;
            }

            if ($type === ActionType::CHOIX) {
                $decision = true;
            }

            if ($type === null || !$type->isCondition()) {
                continue;
            }

            $objectifs[] = $this->objectif($action, $type, $user, $userQuete);
        }

        // Une seule récompense possible → on l'annonce. Plusieurs → elle dépend du choix
        // du joueur, et la dévoiler gâcherait la décision : on dit seulement qu'elle varie.
        $recompenses = count($jeuxDeRecompenses) === 1 ? array_values($jeuxDeRecompenses)[0] : [];

        return [
            'sequenceId' => $sequence->getId(),
            'numero' => (int)$sequence->getPosition(),
            'titre' => $this->titreEtape($sequence),
            'paroles' => $this->paragraphes($sequence->getDialogueContenu()),
            'pnj' => $sequence->getPnj() !== null ? $this->pnj($sequence->getPnj(), $user) : null,
            'objectifs' => $objectifs,
            // Les actions à condition sont des boutons concurrents : UNE suffit.
            'objectifsAuChoix' => count($objectifs) > 1,
            // Rien à accomplir ailleurs, ou au moins un objectif rempli : il ne reste
            // qu'à retourner parler au PNJ.
            'pret' => $objectifs === [] || in_array(true, array_column($objectifs, 'fait'), true),
            'decision' => $decision,
            'recompenses' => $recompenses,
            'recompensesVariables' => count($jeuxDeRecompenses) > 1,
        ];
    }

    private function objectif(Action $action, ActionType $type, User $user, UserQuete $userQuete): array
    {
        $quantite = max(1, (int)$action->getQuantity());
        $progress = $this->questProgressionService->buildProgress($action, $user, $userQuete);
        $item = null;
        $lieu = null;

        try {
            $fait = $this->questProgressionService->isActionConditionMet($action, $user, $userQuete);
        } catch (UnsupportedQuestActionException) {
            // KILL_PVP : réservé, sa condition n'existe pas — on n'affirme rien.
            $fait = null;
        }

        switch ($type) {
            case ActionType::DONNER_OBJET:
            case ActionType::POSSEDER_OBJET:
                $item = $this->item(TypeItem::OBJET, $action->getObjet()?->getId());
                $progress = $this->possession(TypeItem::OBJET, $action->getObjet()?->getId(), $quantite, $user);
                $libelle = ($type === ActionType::DONNER_OBJET ? 'Apporter ' : 'Posséder ')
                    . $this->quantifier($quantite, $action->getObjet()?->getName() ?? 'un objet');
                break;
            case ActionType::DONNER_EQUIPEMENT:
                $item = $this->item(TypeItem::EQUIPEMENT, $action->getEquipement()?->getId());
                $progress = $this->possession(TypeItem::EQUIPEMENT, $action->getEquipement()?->getId(), $quantite, $user);
                $libelle = 'Apporter ' . $this->quantifier($quantite, $action->getEquipement()?->getNom() ?? 'un équipement');
                break;
            case ActionType::DONNER_CONSOMMABLE:
                $item = $this->item(TypeItem::CONSOMMABLE, $action->getConsommable()?->getId());
                $progress = $this->possession(TypeItem::CONSOMMABLE, $action->getConsommable()?->getId(), $quantite, $user);
                $libelle = 'Apporter ' . $this->quantifier($quantite, $action->getConsommable()?->getNom() ?? 'un consommable');
                break;
            case ActionType::DONNER_OR:
                $quantite = max(0, (int)$action->getQuantity());
                $progress = ['current' => min($quantite, $this->sacService->orDisponible($user)), 'target' => $quantite, 'unit' => 'po'];
                $libelle = "Apporter {$quantite} pièces d'or";
                break;
            case ActionType::ATTEINDRE_LEVEL:
                $libelle = 'Atteindre le niveau ' . (int)$action->getQuantity();
                break;
            case ActionType::BATTRE_BOSS:
                $libelle = 'Vaincre ' . ($action->getBoss()?->getName() ?? 'le boss');
                break;
            case ActionType::BATTRE_MONSTRE:
                $libelle = 'Vaincre ' . $this->quantifier($quantite, $action->getMonstre()?->getName() ?? 'un monstre');
                break;
            case ActionType::FABRIQUER_OBJET:
                $libelle = 'Fabriquer ' . $this->quantifier($quantite, $action->getRecette()?->getNom() ?? 'un objet');
                break;
            case ActionType::RECOLTER_RESSOURCE:
                $item = $this->item(TypeItem::OBJET, $action->getObjet()?->getId());
                $libelle = 'Récolter ' . $this->quantifier($quantite, $action->getObjet()?->getName() ?? 'une ressource');
                break;
            case ActionType::PARLER_PNJ:
                $cible = $action->getPnj();
                $lieu = $cible !== null ? $this->pnj($cible, $user)['lieu'] : null;
                $libelle = 'Parler à ' . ($cible?->getName() ?? 'un personnage');
                break;
            case ActionType::VISITER_CARTE:
                $carte = $action->getCarte();
                $lieu = $carte !== null ? [
                    'carteId' => $carte->getId(),
                    'carte' => (string)$carte->getNom(),
                    'x' => null,
                    'y' => null,
                    'ici' => $user->getMap()?->getId() === $carte->getId(),
                ] : null;
                $libelle = 'Se rendre à ' . ($carte?->getNom() ?? 'un lieu');
                break;
            default:
                $libelle = (string)$action->getName();
        }

        return [
            'actionId' => $action->getId(),
            'type' => $type->name,
            'libelle' => $libelle,
            // Le libellé du bouton tel que l'auteur l'a écrit : c'est lui que le joueur
            // cherchera dans le dialogue du PNJ.
            'bouton' => (string)$action->getName(),
            'fait' => $fait,
            'progress' => $progress,
            'item' => $item,
            'lieu' => $lieu,
            'recompenses' => $this->recompenseService->apercu($action->getRecompense()),
        ];
    }

    /**
     * Progression d'un objectif de POSSESSION, sur le DISPONIBLE (possédé − réservé) :
     * c'est ce que la condition vérifie, un objet promis dans un échange ne compte pas.
     */
    private function possession(TypeItem $type, ?int $itemId, int $quantite, User $user): ?array
    {
        if ($itemId === null) {
            return null;
        }

        return [
            'current' => min($quantite, $this->sacService->quantiteDisponible($user, $type, $itemId)),
            'target' => $quantite,
            'unit' => 'en sac',
        ];
    }

    private function item(TypeItem $type, ?int $itemId): ?array
    {
        if ($itemId === null) {
            return null;
        }

        try {
            $description = $this->sacService->decrireItem($type, $itemId);
        } catch (\DomainException) {
            return null;
        }

        return ['type' => $type->value, 'image' => $description['icone'], 'position' => $description['position']];
    }

    /**
     * PNJ + lieu où le trouver. Un PNJ peut être posé sur plusieurs cartes : on préfère
     * celle où se tient le joueur (le plus proche qu'on sache dire), sinon la première.
     */
    private function pnj(Pnj $pnj, User $user): array
    {
        $carteJoueur = $user->getMap()?->getId();
        $lieu = null;
        foreach ($this->carteCarreauRepository->findBy(['pnj' => $pnj], ['id' => 'ASC']) as $case) {
            $carte = $case->getCarte();
            if ($carte === null) {
                continue;
            }

            $candidat = [
                'carteId' => $carte->getId(),
                'carte' => (string)$carte->getNom(),
                'x' => $case->getAbscisse(),
                'y' => $case->getOrdonnee(),
                'ici' => $carteJoueur !== null && $carteJoueur === $carte->getId(),
            ];
            if ($lieu === null || ($candidat['ici'] && !$lieu['ici'])) {
                $lieu = $candidat;
            }
        }

        return [
            'id' => $pnj->getId(),
            'nom' => (string)$pnj->getName(),
            'avatar' => $pnj->getAvatar() ?: null,
            'lieu' => $lieu,
        ];
    }

    private function titreEtape(Sequence $sequence): string
    {
        foreach ([$sequence->getDialogueTitre(), $sequence->getName()] as $titre) {
            if ($titre !== null && trim($titre) !== '') {
                return trim($titre);
            }
        }

        return 'Étape ' . (int)$sequence->getPosition();
    }

    private function quantifier(int $quantite, string $nom): string
    {
        return $quantite > 1 ? "{$quantite} × {$nom}" : $nom;
    }

    /** @return list<string> */
    private function paragraphes(?string $contenu): array
    {
        if ($contenu === null || trim($contenu) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R+/', $contenu)),
            static fn (string $ligne) => $ligne !== ''
        ));
    }
}
