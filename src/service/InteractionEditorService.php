<?php

namespace App\service;

use App\Config\InteractionConfig;
use App\Entity\Interaction;
use App\Entity\InteractionCondition;
use App\Entity\Recompense;
use App\Enum\PorteeRecharge;
use App\Enum\QuestEffect;
use App\Enum\TypeConditionInteraction;
use App\Enum\TypeInteraction;
use App\Exception\InteractionException;
use App\Repository\AlignementRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\ClasseRepository;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\InteractionConditionRepository;
use App\Repository\InteractionRepository;
use App\Repository\MetierRepository;
use App\Repository\ObjetRepository;
use App\Repository\QueteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * InteractionMaker : lecture et sauvegarde complète d'une interaction (fiche + récompense
 * + conditions) en une transaction, avec des ids stables — même patron que
 * QuestEditorService et DonjonEditorService.
 *
 * « Ids stables » : les conditions envoyées avec un id sont mises à jour, celles sans id
 * créées, celles absentes supprimées. On ne repart jamais de zéro.
 */
class InteractionEditorService
{
    public function __construct(
        private readonly InteractionRepository $interactionRepository,
        private readonly InteractionConditionRepository $conditionRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly MetierRepository $metierRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EquipementRepository $equipementRepository,
        private readonly ConsommableRepository $consommableRepository,
        private readonly ClasseRepository $classeRepository,
        private readonly AlignementRepository $alignementRepository,
        private readonly QueteRepository $queteRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    public function lister(): array
    {
        return array_map(fn (Interaction $interaction) => [
            'id' => $interaction->getId(),
            'nom' => $interaction->getNom(),
            'type' => $interaction->getType()->value,
            'actif' => $interaction->isActif(),
            'metier' => $interaction->getMetier()?->getNom(),
            'posees' => $this->carteCarreauRepository->count(['interaction' => $interaction]),
        ], $this->interactionRepository->findBy([], ['nom' => 'ASC']));
    }

    public function pourEditeur(int $interactionId): array
    {
        $interaction = $this->interactionRepository->find($interactionId);
        if ($interaction === null) {
            throw new InteractionException("Interaction introuvable.");
        }

        $recompense = $interaction->getRecompense();

        return [
            'interaction' => [
                'id' => $interaction->getId(),
                'nom' => $interaction->getNom(),
                'type' => $interaction->getType()->value,
                'skin' => $interaction->getSkin(),
                'messageSucces' => $interaction->getMessageSucces(),
                'coutPa' => $interaction->getCoutPa(),
                'effect' => $interaction->getEffect()?->value,
                'effectParams' => $interaction->getEffectParams(),
                'metierId' => $interaction->getMetier()?->getId(),
                'niveauMetierMin' => $interaction->getNiveauMetierMin(),
                'experienceMetier' => $interaction->getExperienceMetier(),
                'cooldownSecondes' => $interaction->getCooldownSecondes(),
                'porteeRecharge' => $interaction->getPorteeRecharge()->value,
                'usageUnique' => $interaction->isUsageUnique(),
                'recolteChoix' => $interaction->isRecolteChoix(),
                'actif' => $interaction->isActif(),
            ],
            'recompense' => [
                'objetId' => $recompense?->getObjet()?->getId(),
                'equipementId' => $recompense?->getEquipement()?->getId(),
                'consommableId' => $recompense?->getConsommable()?->getId(),
                'money' => $recompense?->getMoney(),
                'experience' => $recompense?->getExperience(),
                'quantity' => $recompense?->getQuantity(),
            ],
            // Les conditions et les cases sont RELUES depuis leur repository, jamais depuis
            // la collection de l'entité : après une sauvegarde, celle-ci a pu être chargée
            // avant l'insertion et rendrait un état périmé.
            'conditions' => array_map(fn (InteractionCondition $condition) => [
                'id' => $condition->getId(),
                'type' => $condition->getType()->value,
                'params' => $condition->getParams(),
            ], $this->conditionRepository->findBy(['interaction' => $interaction], ['id' => 'ASC'])),
            'posees' => $this->posees($interaction),
        ];
    }

    /** Catalogues des selects du formulaire, en un seul appel. */
    public function referentiels(): array
    {
        return [
            'metiers' => $this->nommer($this->metierRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'objets' => $this->nommer($this->objetRepository->findBy([], ['name' => 'ASC']), 'getName'),
            'equipements' => $this->nommer($this->equipementRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'consommables' => $this->nommer($this->consommableRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'classes' => $this->nommer($this->classeRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'alignements' => $this->nommer($this->alignementRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'quetes' => $this->nommer($this->queteRepository->findBy([], ['name' => 'ASC']), 'getName'),
        ];
    }

    public function config(): array
    {
        return InteractionConfig::all();
    }

    /* ------------------------------------------------------------------ */
    /* Sauvegarde                                                          */
    /* ------------------------------------------------------------------ */

    public function sauvegarder(array $data): array
    {
        $id = $this->entityManager->wrapInTransaction(function () use ($data): int {
            $interaction = $this->upsertInteraction($data);
            $this->upsertRecompense($interaction, $data['recompense'] ?? []);
            $this->upsertConditions($interaction, $data['conditions'] ?? []);
            $this->entityManager->flush();

            return $interaction->getId();
        });

        return $this->pourEditeur($id);
    }

    public function supprimer(int $interactionId): void
    {
        $interaction = $this->interactionRepository->find($interactionId);
        if ($interaction === null) {
            throw new InteractionException("Interaction introuvable.");
        }

        // Supprimer une interaction encore posée laisserait des cases orphelines : mieux
        // vaut le dire que de casser silencieusement une carte.
        $posees = $this->posees($interaction);
        if ($posees !== []) {
            $nombre = count($posees);
            throw new InteractionException(
                "Cette interaction est posée sur {$nombre} case" . ($nombre > 1 ? 's' : '')
                . ". Retirez-la des cartes avant de la supprimer, ou désactivez-la."
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($interaction): void {
            foreach ($interaction->getConditions() as $condition) {
                $this->entityManager->remove($condition);
            }
            $this->entityManager->remove($interaction);
            $this->entityManager->flush();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function upsertInteraction(array $data): Interaction
    {
        $interaction = isset($data['id']) ? $this->interactionRepository->find((int)$data['id']) : new Interaction();
        if ($interaction === null) {
            throw new InteractionException("Interaction introuvable.");
        }

        $nom = trim((string)($data['nom'] ?? ''));
        if ($nom === '') {
            throw new InteractionException("L'interaction doit avoir un nom.");
        }

        $type = TypeInteraction::tryFrom((string)($data['type'] ?? '')) ?? TypeInteraction::ACTIONNER;
        $portee = PorteeRecharge::tryFrom((string)($data['porteeRecharge'] ?? '')) ?? PorteeRecharge::JOUEUR;

        $metierId = $data['metierId'] ?? null;
        $metier = $metierId === null ? null : $this->metierRepository->find((int)$metierId);
        if ($metierId !== null && $metier === null) {
            throw new InteractionException("Métier introuvable.");
        }

        $niveauMetier = max(0, (int)($data['niveauMetierMin'] ?? 0));
        if ($metier !== null && $niveauMetier < 1) {
            // Sinon la condition serait toujours vraie : « niveau 0 » veut dire
            // « n'a jamais pratiqué le métier ».
            $niveauMetier = 1;
        }

        $interaction
            ->setNom($nom)
            ->setType($type)
            ->setSkin(($data['skin'] ?? null) ?: null)
            ->setMessageSucces(($data['messageSucces'] ?? null) ?: null)
            ->setCoutPa((int)($data['coutPa'] ?? 0))
            ->setEffect(QuestEffect::tryFrom((string)($data['effect'] ?? '')))
            ->setEffectParams($this->parserJson($data['effectParams'] ?? null))
            ->setMetier($metier)
            ->setNiveauMetierMin($niveauMetier)
            ->setExperienceMetier(max(0, (int)($data['experienceMetier'] ?? 0)))
            ->setCooldownSecondes(max(0, (int)($data['cooldownSecondes'] ?? 0)))
            ->setPorteeRecharge($portee)
            ->setUsageUnique((bool)($data['usageUnique'] ?? false))
            ->setRecolteChoix((bool)($data['recolteChoix'] ?? false))
            ->setActif((bool)($data['actif'] ?? true));

        $this->entityManager->persist($interaction);
        $this->entityManager->flush(); // id nécessaire pour rattacher les conditions

        return $interaction;
    }

    /**
     * La récompense est une ligne `Recompense` dédiée à cette interaction : on la met à
     * jour en place (id stable) plutôt que d'en recréer une à chaque sauvegarde.
     */
    private function upsertRecompense(Interaction $interaction, array $data): void
    {
        $vide = ($data['objetId'] ?? null) === null
            && ($data['equipementId'] ?? null) === null
            && ($data['consommableId'] ?? null) === null
            && (int)($data['money'] ?? 0) === 0
            && (int)($data['experience'] ?? 0) === 0;

        if ($vide) {
            $ancienne = $interaction->getRecompense();
            $interaction->setRecompense(null);
            if ($ancienne !== null) {
                $this->entityManager->remove($ancienne);
            }

            return;
        }

        $recompense = $interaction->getRecompense() ?? new Recompense();
        $recompense
            ->setObjet($this->trouver($this->objetRepository, $data['objetId'] ?? null))
            ->setEquipement($this->trouver($this->equipementRepository, $data['equipementId'] ?? null))
            ->setConsommable($this->trouver($this->consommableRepository, $data['consommableId'] ?? null))
            ->setMoney(($data['money'] ?? null) === null ? null : max(0, (int)$data['money']))
            ->setExperience(($data['experience'] ?? null) === null ? null : max(0, (int)$data['experience']))
            ->setQuantity(($data['quantity'] ?? null) === null ? null : max(1, (int)$data['quantity']));

        $this->entityManager->persist($recompense);
        $interaction->setRecompense($recompense);
    }

    private function upsertConditions(Interaction $interaction, array $conditionsData): void
    {
        $existantes = [];
        foreach ($this->conditionRepository->findBy(['interaction' => $interaction]) as $condition) {
            $existantes[$condition->getId()] = $condition;
        }

        $conservees = [];
        foreach ($conditionsData as $data) {
            $type = TypeConditionInteraction::tryFrom((string)($data['type'] ?? ''));
            if ($type === null) {
                throw new InteractionException("Type de condition inconnu.");
            }

            $id = isset($data['id']) ? (int)$data['id'] : null;
            $condition = $id !== null && isset($existantes[$id]) ? $existantes[$id] : new InteractionCondition();
            $condition
                ->setInteraction($interaction)
                ->setType($type)
                ->setParams($this->nettoyerParams($type, $data['params'] ?? []));

            $this->entityManager->persist($condition);
            if ($condition->getId() !== null) {
                $conservees[$condition->getId()] = true;
            }
        }

        foreach ($existantes as $id => $condition) {
            if (!isset($conservees[$id])) {
                $this->entityManager->remove($condition);
            }
        }
    }

    /** Clés inconnues écartées, manquantes complétées : une condition est toujours évaluable. */
    private function nettoyerParams(TypeConditionInteraction $type, mixed $params): array
    {
        $params = is_array($params) ? $params : [];
        $propre = [];
        foreach ($type->parametres() as $cle => $defaut) {
            $valeur = $params[$cle] ?? $defaut;
            $propre[$cle] = $valeur === null ? null : (int)$valeur;
        }

        return $propre;
    }

    private function parserJson(mixed $brut): ?array
    {
        if ($brut === null || $brut === '' || $brut === []) {
            return null;
        }
        if (is_array($brut)) {
            return $brut;
        }

        $decode = json_decode((string)$brut, true);
        if (!is_array($decode)) {
            throw new InteractionException("Les paramètres de l'effet doivent être un objet JSON valide.");
        }

        return $decode;
    }

    /** @return array<int, array{carteCarreauId: int, carte: string, abscisse: int, ordonnee: int}> */
    private function posees(Interaction $interaction): array
    {
        return array_map(fn ($case) => [
            'carteCarreauId' => $case->getId(),
            'carte' => $case->getCarte()?->getNom(),
            'abscisse' => $case->getAbscisse(),
            'ordonnee' => $case->getOrdonnee(),
        ], $this->carteCarreauRepository->findBy(['interaction' => $interaction]));
    }

    private function trouver(object $repository, mixed $id): ?object
    {
        return $id === null || $id === '' ? null : $repository->find((int)$id);
    }

    private function nommer(array $entites, string $getter): array
    {
        return array_map(fn ($entite) => ['id' => $entite->getId(), 'nom' => $entite->$getter()], $entites);
    }
}
