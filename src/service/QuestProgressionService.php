<?php

namespace App\service;

use App\Entity\Action;
use App\Entity\Pnj;
use App\Entity\Quete;
use App\Entity\Sequence;
use App\Entity\User;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Enum\TypeCompteur;
use App\Enum\TypeItem;
use App\Exception\QuestException;
use App\Exception\UnsupportedQuestActionException;
use App\Repository\ActionRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserQueteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine à états des quêtes — unique chemin de démarrage, de validation
 * d'action et d'avancement. L'ordre des séquences est porté par
 * Sequence.position seul ; la fin de quête = pas de séquence à position + 1.
 *
 * Toutes les réponses joueur partagent la même forme :
 * {status, quest, step, blockedMessages, feedback: {rewards, messages}, needRefresh}
 * — status ∈ step | blocked | done | locked. Aucun HTML : le front rend
 * dialogue.paragraphs et les messages en texte brut.
 *
 * Deux mécaniques transverses passent par ici et nulle part ailleurs :
 *
 *  - **Le karma des choix.** Une action peut porter un `karma` (positif ou négatif),
 *    appliqué APRÈS que la condition est remplie et le coût payé — un choix refusé
 *    ne vaut aucun jugement moral. L'ajustement passe par KarmaService, seul point
 *    de mutation, qui borne la valeur.
 *
 *  - **Les objectifs comptés** (BATTRE_MONSTRE, FABRIQUER_OBJET, RECOLTER_RESSOURCE).
 *    Les compteurs de `joueur_compteur` sont cumulatifs à vie ; ce qui rend l'objectif
 *    demandable est l'INSTANTANÉ pris à l'entrée dans l'étape
 *    (`user_quete.compteurs_depart`), reposé à chaque changement de séquence et
 *    jamais pendant qu'on piétine sur la même. « Tuez 5 loups » veut donc dire cinq
 *    loups depuis la demande, pas cinq loups dans une vie.
 */
class QuestProgressionService
{
    public function __construct(
        private readonly SequenceRepository $sequenceRepository,
        private readonly UserQueteRepository $userQueteRepository,
        private readonly ActionRepository $actionRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        private readonly UserBossRepository $userBossRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly SacService $sacService,
        private readonly RecompenseService $recompenseService,
        private readonly QuestEffectRegistry $effectRegistry,
        private readonly CompteurJoueurService $compteurJoueurService,
        private readonly KarmaService $karmaService,
        private readonly EntityManagerInterface $entityManager
    ){}

    /**
     * État de la quête d'un PNJ pour le joueur, SANS effet de bord
     * (l'ancien /api/pnj démarrait la quête à la simple consultation).
     * status ∈ available | locked | inProgress | done.
     */
    public function getQuestStatusForPnj(User $user, Pnj $pnj): array
    {
        $quete = $pnj->getQuete();
        if ($quete === null) {
            throw new QuestException("Ce PNJ ne porte aucune quête.");
        }

        $questInfo = ['id' => $quete->getId(), 'name' => $quete->getName()];
        $userQuete = $this->userQueteRepository->findOneBy(['user' => $user, 'quete' => $quete]);

        if ($userQuete !== null) {
            if ($userQuete->getIsDone()) {
                return $questInfo + ['status' => 'done'];
            }

            return $questInfo + [
                'status' => 'inProgress',
                'step' => $this->buildStepPayload($userQuete->getSequence(), $user, $userQuete),
            ];
        }

        $lockedReasons = $this->checkPrerequisites($user, $quete);
        if ($lockedReasons !== []) {
            return $questInfo + ['status' => 'locked', 'lockedReasons' => $lockedReasons];
        }

        return $questInfo + [
            'status' => 'available',
            'introduction' => $this->splitParagraphs($quete->getIntroduction()),
        ];
    }

    /**
     * Démarre la quête portée par le PNJ après vérification des prérequis.
     * Idempotent : si la quête est déjà commencée, renvoie l'étape courante
     * sans créer de doublon (contrainte unique user+quete en base).
     */
    public function startQuest(User $user, Pnj $pnj): array
    {
        $quete = $pnj->getQuete();
        if ($quete === null) {
            throw new QuestException("Ce PNJ ne porte aucune quête.");
        }

        $questInfo = ['id' => $quete->getId(), 'name' => $quete->getName()];
        $existing = $this->userQueteRepository->findOneBy(['user' => $user, 'quete' => $quete]);
        if ($existing !== null) {
            if ($existing->getIsDone()) {
                return $this->buildResponse('done', $questInfo);
            }

            return $this->buildResponse('step', $questInfo, $this->buildStepPayload($existing->getSequence(), $user, $existing));
        }

        $lockedReasons = $this->checkPrerequisites($user, $quete);
        if ($lockedReasons !== []) {
            return $this->buildResponse('locked', $questInfo, blockedMessages: $lockedReasons);
        }

        $firstSequence = $this->sequenceRepository->findOneBy(['quete' => $quete, 'position' => 1]);
        if ($firstSequence === null) {
            throw new QuestException("La quête « {$quete->getName()} » n'a aucune séquence.");
        }

        $userQuete = new UserQuete();
        $userQuete->setUser($user);
        $userQuete->setQuete($quete);
        $userQuete->setSequence($firstSequence);
        $userQuete->setIsDone(false);
        // Photo des compteurs AVANT que le joueur ne commence : sans elle, une première
        // étape « tuez 5 loups » serait déjà remplie pour qui en a tué cinq autrefois.
        $this->snapshotCompteurs($user, $userQuete, $firstSequence);
        $this->entityManager->persist($userQuete);
        $this->entityManager->flush();

        return $this->buildResponse('step', $questInfo, $this->buildStepPayload($firstSequence, $user, $userQuete));
    }

    /**
     * Exécute une action de la séquence courante : vérifie la condition,
     * consomme les ressources, applique l'effet scripté éventuel, donne la
     * récompense de la séquence et avance (position + 1, done si aucune).
     * Une séquence sans quête (dialogue de PNJ "action") ne progresse pas.
     */
    public function executeAction(User $user, int $sequenceId, int $actionId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $sequenceId, $actionId): array {
            $sequence = $this->sequenceRepository->find($sequenceId);
            if ($sequence === null) {
                throw new QuestException("Séquence introuvable.");
            }

            $action = $this->findActionInSequence($sequence, $actionId);
            $quete = $sequence->getQuete();
            $questInfo = $quete !== null ? ['id' => $quete->getId(), 'name' => $quete->getName()] : null;

            // Dialogue autonome : la proximité du PNJ est vérifiée côté serveur
            // (le front ne permet le clic qu'adjacent, mais l'API doit se défendre seule).
            if ($quete === null && !$this->isUserAdjacentToPnj($user, $sequence->getPnj())) {
                throw new QuestException("Vous êtes trop loin de ce PNJ.");
            }

            $userQuete = null;
            if ($quete !== null) {
                $userQuete = $this->userQueteRepository->findOneBy(['user' => $user, 'quete' => $quete]);
                if ($userQuete === null) {
                    throw new QuestException("Vous n'avez pas commencé cette quête.");
                }
                if ($userQuete->getIsDone()) {
                    throw new QuestException("Cette quête est déjà terminée.");
                }
                if ($userQuete->getSequence()?->getId() !== $sequence->getId()) {
                    throw new QuestException("Cette étape n'est pas votre étape courante de la quête.");
                }
            }

            $type = $action->getActionType();
            if ($type === null || !$type->isImplemented()) {
                throw new UnsupportedQuestActionException($type ?? ActionType::CHOIX);
            }

            if (!$this->isActionConditionMet($action, $user, $userQuete)) {
                return $this->buildResponse(
                    'blocked',
                    $questInfo,
                    $this->buildStepPayload($sequence, $user, $userQuete),
                    blockedMessages: [$this->blockedMessage($action, $user, $userQuete)]
                );
            }

            $this->consumeActionCost($action, $user);

            $effectMessages = [];
            $needRefresh = $this->isConsumingType($type);
            if ($type === ActionType::SCRIPTED_EFFECT) {
                $effect = $action->getEffect();
                if ($effect === null) {
                    throw new QuestException("L'action « {$action->getName()} » n'a pas d'effet configuré.");
                }
                $effectResult = $this->effectRegistry->execute($effect, $action->getEffectParams() ?? [], $user);
                $effectMessages = $effectResult['messages'];
                $needRefresh = $needRefresh || $effectResult['needRefresh'];
            }

            // Le karma vient APRÈS la condition et le coût : un choix que le joueur n'a
            // pas pu tenir n'engage pas sa réputation. Il vaut aussi pour les dialogues
            // autonomes — un PNJ sans quête peut proposer un choix qui compte.
            $karma = $this->applyActionKarma($user, $action, $effectMessages);

            // La récompense est portée par l'action jouée (par branche/choix).
            ['rewards' => $rewards, 'playerXp' => $playerXp] = $this->giveActionReward($user, $action);
            $needRefresh = $needRefresh || $rewards !== [];

            // Dialogue autonome (PNJ "action") : pas de progression à gérer.
            if ($quete === null) {
                return $this->buildResponse(
                    'done',
                    null,
                    $this->buildStepPayload($sequence, $user),
                    rewards: $rewards,
                    feedbackMessages: $effectMessages,
                    needRefresh: $needRefresh,
                    playerXp: $playerXp,
                    karma: $karma
                );
            }

            // Branchement : un choix peut terminer la quête, sauter vers une
            // séquence précise, ou (par défaut) suivre l'ordre linéaire position + 1.
            $nextSequence = $action->getEndsQuest() === true
                ? null
                : ($action->getNextSequence() ?? $this->sequenceRepository->findOneBy([
                    'quete' => $quete,
                    'position' => $sequence->getPosition() + 1,
                ]));

            if ($nextSequence === null) {
                $userQuete->setIsDone(true);
                $this->entityManager->persist($userQuete);

                return $this->buildResponse(
                    'done',
                    $questInfo,
                    rewards: $rewards,
                    feedbackMessages: $effectMessages,
                    needRefresh: $needRefresh,
                    playerXp: $playerXp,
                    karma: $karma
                );
            }

            $userQuete->setSequence($nextSequence);
            // Nouvelle étape = nouvelle photo des compteurs. C'est le SEUL moment où
            // l'instantané est reposé : le faire à chaque tentative remettrait la
            // progression de « tuez 5 loups » à zéro dès que le joueur reclique.
            $this->snapshotCompteurs($user, $userQuete, $nextSequence);
            $this->entityManager->persist($userQuete);

            return $this->buildResponse(
                'step',
                $questInfo,
                $this->buildStepPayload($nextSequence, $user, $userQuete),
                rewards: $rewards,
                feedbackMessages: $effectMessages,
                needRefresh: $needRefresh,
                playerXp: $playerXp,
                karma: $karma
            );
        });
    }

    /**
     * Action posée sur une case de la carte (carte_carreau.action) : effet
     * scripté uniquement, le joueur doit être sur la case ou adjacent.
     */
    public function executeMapAction(User $user, int $actionId): array
    {
        $action = $this->actionRepository->find($actionId);
        if ($action === null) {
            throw new QuestException("Action introuvable.");
        }

        $case = $this->carteCarreauRepository->findOneBy(['action' => $action]);
        if ($case === null) {
            throw new QuestException("Cette action n'est posée sur aucune case.");
        }

        $isAdjacent = $user->getMap() !== null
            && $case->getCarte()->getId() === $user->getMap()->getId()
            && abs($case->getAbscisse() - $user->getCaseAbscisse()) <= 1
            && abs($case->getOrdonnee() - $user->getCaseOrdonnee()) <= 1;
        if (!$isAdjacent) {
            throw new QuestException("Vous êtes trop loin pour faire cette action.");
        }

        $effect = $action->getEffect();
        if ($action->getActionType() !== ActionType::SCRIPTED_EFFECT || $effect === null) {
            throw new QuestException("L'action « {$action->getName()} » n'a pas d'effet configuré.");
        }

        // La case est fournie aux effets : un levier de donjon a besoin de savoir LEQUEL
        // il est, et cette information n'a pas à être recopiée à la main dans les params.
        $params = ($action->getEffectParams() ?? []) + ['carteCarreauId' => $case->getId()];

        return $this->entityManager->wrapInTransaction(function () use ($effect, $params, $user, $action): array {
            $resultat = $this->effectRegistry->execute($effect, $params, $user);
            // Une action posée sur une case est la MÊME entité qu'un bouton de quête :
            // si l'auteur y a mis du karma, il doit compter ici aussi, sinon la même
            // fiche se comporterait différemment selon l'endroit où elle est branchée.
            $resultat['karma'] = $this->applyActionKarma($user, $action, $resultat['messages']);

            return $resultat;
        });
    }

    /**
     * Payload d'une étape : dialogue en paragraphes + boutons typés. Aucun HTML.
     *
     * `$user` et `$userQuete` sont facultatifs : sans eux (dialogue autonome, appels
     * de lecture qui n'ont pas la progression sous la main) le payload est identique,
     * simplement sans la clé `progress`. Un bouton d'objectif compté porte
     * `progress: {current, target, unit}` — le joueur doit pouvoir lire « 3 / 10 »
     * plutôt que de cliquer à l'aveugle pour savoir où il en est.
     */
    public function buildStepPayload(?Sequence $sequence, ?User $user = null, ?UserQuete $userQuete = null): ?array
    {
        if ($sequence === null) {
            return null;
        }

        $actions = [];
        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            $action = $sequenceAction->getAction();
            $payload = [
                'actionId' => $action->getId(),
                'type' => $action->getActionType()?->name,
                'label' => $action->getName(),
            ];

            $progress = $user !== null ? $this->buildProgress($action, $user, $userQuete) : null;
            if ($progress !== null) {
                $payload['progress'] = $progress;
            }

            $actions[] = $payload;
        }

        return [
            'sequenceId' => $sequence->getId(),
            'dialogue' => [
                'title' => $sequence->getDialogueTitre() ?? '',
                'paragraphs' => $this->splitParagraphs($sequence->getDialogueContenu()),
            ],
            'actions' => $actions,
        ];
    }

    /** Prérequis de démarrage — renvoie les raisons de blocage (vide = ok). */
    public function checkPrerequisites(User $user, Quete $quete): array
    {
        $reasons = [];

        $minimalLevel = $quete->getMinimalLevel();
        if ($minimalLevel !== null && $minimalLevel > 0) {
            $level = $this->niveauJoueurRepository->getPlayerLevel($user->getId()) ?? 1;
            if ($level < $minimalLevel) {
                $reasons[] = "Niveau {$minimalLevel} requis (vous êtes niveau {$level}).";
            }
        }

        $alignement = $quete->getAlignement();
        if ($alignement !== null && $user->getAlignement()?->getId() !== $alignement->getId()) {
            $reasons[] = "Cette quête est réservée à l'alignement {$alignement->getNom()}.";
        }

        $prerequisiteQuete = $quete->getQuete();
        if ($prerequisiteQuete !== null) {
            $prerequisiteDone = $this->userQueteRepository->findOneBy(['user' => $user, 'quete' => $prerequisiteQuete]);
            if ($prerequisiteDone === null || !$prerequisiteDone->getIsDone()) {
                $reasons[] = "Vous devez d'abord terminer la quête « {$prerequisiteQuete->getName()} ».";
            }
        }

        $objet = $quete->getObjet();
        if ($objet !== null && !$this->userHasObjet($user, $objet->getId(), 1)) {
            $reasons[] = "Vous devez posséder l'objet {$objet->getName()}.";
        }

        return $reasons;
    }

    /**
     * Vérifie la condition d'une action sans rien consommer.
     *
     * `$userQuete` porte l'instantané des compteurs : il n'est nul que pour les
     * dialogues autonomes, qui ne portent aucun objectif compté.
     */
    public function isActionConditionMet(Action $action, User $user, ?UserQuete $userQuete = null): bool
    {
        $compteur = $action->getActionType()?->compteur();
        if ($compteur !== null) {
            return $this->verifyCompteur($action, $user, $userQuete) >= max(1, (int)$action->getQuantity());
        }

        return match ($action->getActionType()) {
            ActionType::DONNER_OBJET,
            ActionType::POSSEDER_OBJET => $this->userHasObjet($user, $action->getObjet()?->getId() ?? 0, max(1, (int)$action->getQuantity())),
            ActionType::DONNER_OR => $this->sacService->orDisponible($user) >= $action->getQuantity(),
            ActionType::DONNER_EQUIPEMENT => $this->verifyEquipementInventaire($action, $user),
            ActionType::DONNER_CONSOMMABLE => $this->verifyConsommableInventaire($action, $user),
            ActionType::ATTEINDRE_LEVEL => ($this->niveauJoueurRepository->getPlayerLevel($user->getId()) ?? 1) >= $action->getQuantity(),
            ActionType::BATTRE_BOSS => $this->verifyBossKilled($action, $user),
            ActionType::PARLER_PNJ => $this->verifyPnjProximity($action, $user),
            ActionType::VISITER_CARTE => $this->verifyCarteVisited($action, $user),
            ActionType::SCRIPTED_EFFECT, ActionType::PASSER_DIALOGUE, ActionType::CHOIX => true,
            default => throw new UnsupportedQuestActionException($action->getActionType()),
        };
    }

    /** Consomme le coût d'une action DONNER_* (après isActionConditionMet). */
    private function consumeActionCost(Action $action, User $user): void
    {
        try {
            switch ($action->getActionType()) {
                case ActionType::DONNER_OR:
                    $this->sacService->debiterOr($user, max(0, (int) $action->getQuantity()));
                    break;
                case ActionType::DONNER_OBJET:
                    $this->sacService->retirerItem($user, TypeItem::OBJET, $action->getObjet()?->getId() ?? 0, max(1, (int) $action->getQuantity()));
                    break;
                case ActionType::DONNER_EQUIPEMENT:
                    $this->sacService->retirerItem($user, TypeItem::EQUIPEMENT, $action->getEquipement()?->getId() ?? 0, max(1, (int) $action->getQuantity()));
                    break;
                case ActionType::DONNER_CONSOMMABLE:
                    $this->sacService->retirerItem($user, TypeItem::CONSOMMABLE, $action->getConsommable()?->getId() ?? 0, max(1, (int) $action->getQuantity()));
                    break;
                default:
                    break;
            }
        } catch (\DomainException $exception) {
            // Les messages de SacService sont déjà en français et destinés au joueur.
            throw new QuestException($exception->getMessage());
        }
    }

    /**
     * Donne la récompense de l'action jouée (par branche/choix). Renvoie :
     *  - 'rewards'  : items obtenus [{type, label, quantity}] pour le feedback front ;
     *  - 'playerXp' : {experience, level, experienceMax} si de l'XP a été donnée
     *    (pour que le front rafraîchisse la barre/le niveau sans rechargement), sinon null.
     */
    private function giveActionReward(User $user, Action $action): array
    {
        return $this->recompenseService->distribuer($user, $action->getRecompense());
    }

    private function buildResponse(
        string $status,
        ?array $questInfo,
        ?array $step = null,
        array $rewards = [],
        array $blockedMessages = [],
        array $feedbackMessages = [],
        bool $needRefresh = false,
        ?array $playerXp = null,
        ?array $karma = null
    ): array {
        return [
            'status' => $status,
            'quest' => $questInfo,
            'step' => $step,
            'blockedMessages' => $blockedMessages,
            'feedback' => [
                'rewards' => $rewards,
                'messages' => array_map(fn (string $text) => ['type' => 'info', 'text' => $text], $feedbackMessages),
            ],
            'needRefresh' => $needRefresh,
            // {experience, level, experienceMax} après un gain d'XP de quête, sinon null.
            'playerXp' => $playerXp,
            // {karma, palier, delta} quand le choix joué a bougé le karma, sinon null —
            // même forme que ce que renvoient la récolte et la fabrication.
            'karma' => $karma,
        ];
    }

    /**
     * Applique le karma porté par l'action jouée et ajoute la phrase de feedback.
     *
     * Renvoie null quand rien n'a bougé — soit l'action n'engage aucun karma, soit la
     * borne était déjà atteinte. Annoncer « karma +5 » à un joueur déjà au maximum
     * serait un mensonge que `KarmaService::ajuster` permet précisément d'éviter
     * (`delta` = l'ajustement RÉELLEMENT appliqué).
     *
     * @param string[] $messages complété en place
     */
    private function applyActionKarma(User $user, Action $action, array &$messages): ?array
    {
        $delta = (int)($action->getKarma() ?? 0);
        if ($delta === 0) {
            return null;
        }

        $ajustement = $this->karmaService->ajuster($user, $delta);
        if ($ajustement['delta'] === 0) {
            return null;
        }

        $messages[] = sprintf(
            $ajustement['delta'] > 0
                ? "Votre conduite vous honore (karma %+d)."
                : "Ce choix vous coûte en réputation (karma %+d).",
            $ajustement['delta']
        );

        return $ajustement;
    }

    /**
     * Photographie les compteurs lus par les actions de `$sequence`.
     *
     * Seuls les compteurs effectivement visés sont enregistrés : l'instantané reste
     * minuscule et une action rebranchée sur une autre cible ne traîne pas de départ
     * périmé. Une clé absente vaut 0, c'est-à-dire une lecture cumulative — visible
     * dans le compte affiché au joueur, jamais bloquante.
     */
    private function snapshotCompteurs(User $user, UserQuete $userQuete, Sequence $sequence): void
    {
        $depart = [];
        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            $action = $sequenceAction->getAction();
            $compteur = $action->getActionType()?->compteur();
            if ($compteur === null) {
                continue;
            }

            $cibleId = $this->cibleCompteur($action, $compteur);
            if ($cibleId <= 0) {
                continue;
            }

            $depart[$compteur->cle($cibleId)] = $this->compteurJoueurService->valeur($user, $compteur, $cibleId);
        }

        $userQuete->setCompteursDepart($depart);
    }

    /** Progression du joueur sur l'objectif compté d'une action, depuis l'instantané. */
    private function verifyCompteur(Action $action, User $user, ?UserQuete $userQuete): int
    {
        $compteur = $action->getActionType()?->compteur();
        if ($compteur === null) {
            return 0;
        }

        $cibleId = $this->cibleCompteur($action, $compteur);
        if ($cibleId <= 0) {
            return 0;
        }

        $depart = $userQuete?->getCompteurDepart($compteur->cle($cibleId)) ?? 0;

        return $this->compteurJoueurService->progression($user, $compteur, $cibleId, $depart);
    }

    /** Quelle cible d'action porte le compteur : le type le dit (cf. TypeCompteur). */
    private function cibleCompteur(Action $action, TypeCompteur $compteur): int
    {
        return match ($compteur) {
            TypeCompteur::MONSTRE_TUE => (int)($action->getMonstre()?->getId() ?? 0),
            TypeCompteur::OBJET_FABRIQUE => (int)($action->getRecette()?->getId() ?? 0),
            TypeCompteur::RESSOURCE_RECOLTEE => (int)($action->getObjet()?->getId() ?? 0),
        };
    }

    /** {current, target, unit} pour un bouton d'objectif compté — null sinon. */
    private function buildProgress(Action $action, User $user, ?UserQuete $userQuete): ?array
    {
        $compteur = $action->getActionType()?->compteur();
        if ($compteur === null) {
            return null;
        }

        return [
            'current' => $this->verifyCompteur($action, $user, $userQuete),
            'target' => max(1, (int)$action->getQuantity()),
            'unit' => $compteur->unite(),
        ];
    }

    private function findActionInSequence(Sequence $sequence, int $actionId): Action
    {
        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            if ($sequenceAction->getAction()->getId() === $actionId) {
                return $sequenceAction->getAction();
            }
        }

        throw new QuestException("Cette action n'appartient pas à cette étape.");
    }

    private function blockedMessage(Action $action, ?User $user = null, ?UserQuete $userQuete = null): string
    {
        $message = trim((string)$action->getMessage());
        if ($message !== '') {
            return $message;
        }

        // Objectif compté : le message par défaut donne le chiffre. « Condition non
        // remplie » sur une chasse laisserait le joueur sans savoir s'il lui reste un
        // monstre ou dix.
        $compteur = $action->getActionType()?->compteur();
        if ($compteur !== null && $user !== null) {
            $cible = max(1, (int)$action->getQuantity());
            $fait = $this->verifyCompteur($action, $user, $userQuete);

            return "Ce n'est pas encore fait : {$fait} / {$cible} {$compteur->unite()}.";
        }

        return match ($action->getActionType()) {
            ActionType::DONNER_OBJET, ActionType::POSSEDER_OBJET => "Vous n'avez pas les objets nécessaires.",
            ActionType::DONNER_OR => "Vous n'avez pas assez d'or.",
            ActionType::DONNER_EQUIPEMENT => "Vous n'avez pas l'équipement nécessaire.",
            ActionType::DONNER_CONSOMMABLE => "Vous n'avez pas les consommables nécessaires.",
            ActionType::ATTEINDRE_LEVEL => "Vous n'avez pas le niveau requis.",
            ActionType::BATTRE_BOSS => "Vous devez d'abord vaincre " . ($action->getBoss()?->getName() ?? "le boss") . ".",
            ActionType::PARLER_PNJ => "Vous devez d'abord aller parler à " . ($action->getPnj()?->getName() ?? "un PNJ") . ".",
            ActionType::VISITER_CARTE => "Vous devez d'abord visiter " . ($action->getCarte()?->getNom() ?? "une carte") . ".",
            default => "Condition non remplie.",
        };
    }

    private function isConsumingType(ActionType $type): bool
    {
        return in_array($type, [
            ActionType::DONNER_OR,
            ActionType::DONNER_OBJET,
            ActionType::DONNER_EQUIPEMENT,
            ActionType::DONNER_CONSOMMABLE,
        ], true);
    }

    private function splitParagraphs(?string $contenu): array
    {
        if ($contenu === null || trim($contenu) === '') {
            return [];
        }

        $lines = preg_split('/\R+/', $contenu);

        return array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));
    }

    // Les possessions sont mesurées sur le DISPONIBLE (possédé − réservé par un échange) :
    // un objet promis à un autre joueur ne peut pas servir à valider ou payer une quête.
    private function userHasObjet(User $user, int $objetId, int $quantity): bool
    {
        if ($objetId <= 0) {
            return false;
        }

        return $this->sacService->quantiteDisponible($user, TypeItem::OBJET, $objetId) >= $quantity;
    }

    private function verifyEquipementInventaire(Action $action, User $user): bool
    {
        return $this->sacService->quantiteDisponible($user, TypeItem::EQUIPEMENT, $action->getEquipement()?->getId() ?? 0)
            >= max(1, (int) $action->getQuantity());
    }

    private function verifyConsommableInventaire(Action $action, User $user): bool
    {
        return $this->sacService->quantiteDisponible($user, TypeItem::CONSOMMABLE, $action->getConsommable()?->getId() ?? 0)
            >= max(1, (int) $action->getQuantity());
    }

    private function verifyBossKilled(Action $action, User $user): bool
    {
        $userBoss = $this->userBossRepository->findOneBy(['user' => $user, 'boss' => $action->getBoss()]);

        return $userBoss !== null && $userBoss->getNumberKill() >= max(1, (int)$action->getQuantity());
    }

    /** Le joueur doit être sur la même carte et adjacent (rayon 1) au PNJ cible. */
    private function verifyPnjProximity(Action $action, User $user): bool
    {
        return $this->isUserAdjacentToPnj($user, $action->getPnj());
    }

    private function isUserAdjacentToPnj(User $user, ?Pnj $pnj): bool
    {
        $pnjCase = $this->carteCarreauRepository->findOneBy(['pnj' => $pnj]);
        if ($pnjCase === null || $user->getMap() === null) {
            return false;
        }

        return $pnjCase->getCarte()->getId() === $user->getMap()->getId()
            && abs($pnjCase->getAbscisse() - $user->getCaseAbscisse()) <= 1
            && abs($pnjCase->getOrdonnee() - $user->getCaseOrdonnee()) <= 1;
    }

    private function verifyCarteVisited(Action $action, User $user): bool
    {
        return $user->getMap() !== null
            && $action->getCarte() !== null
            && $user->getMap()->getId() === $action->getCarte()->getId();
    }

}
