<?php

namespace App\service;

use App\Config\QuestActionTypeConfig;
use App\Entity\Action;
use App\Entity\Quete;
use App\Entity\Recompense;
use App\Entity\Sequence;
use App\Entity\SequenceAction;
use App\Enum\ActionType;
use App\Enum\QuestEffect;
use App\Exception\QuestException;
use App\Repository\AlignementRepository;
use App\Repository\BossRepository;
use App\Repository\CarteRepository;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\ObjetRepository;
use App\Repository\PnjRepository;
use App\Repository\QueteRepository;
use App\Repository\UserQueteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sauvegarde du QuestMaker : upsert transactionnel par correspondance d'ids
 * (les séquences/actions existantes sont mises à jour, jamais supprimées puis
 * recréées — leurs ids restent stables). Les éléments absents du payload sont
 * supprimés ; la position est TOUJOURS recalculée côté serveur depuis l'ordre
 * du payload.
 */
class QuestEditorService
{
    public function __construct(
        private readonly QueteRepository $queteRepository,
        private readonly UserQueteRepository $userQueteRepository,
        private readonly PnjRepository $pnjRepository,
        private readonly AlignementRepository $alignementRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EquipementRepository $equipementRepository,
        private readonly ConsommableRepository $consommableRepository,
        private readonly BossRepository $bossRepository,
        private readonly CarteRepository $carteRepository,
        private readonly EntityManagerInterface $entityManager
    ){}

    public function listQuests(): array
    {
        $quests = [];
        foreach ($this->queteRepository->findAll() as $quete) {
            $quests[] = ['id' => $quete->getId(), 'name' => $quete->getName()];
        }

        return $quests;
    }

    /** Payload complet d'une quête, miroir exact de ce que saveQuest() accepte. */
    public function getQuestForEditor(int $questId): array
    {
        $quete = $this->queteRepository->find($questId);
        if ($quete === null) {
            throw new QuestException("Quête introuvable.");
        }

        $sequences = [];
        foreach ($this->getOrderedSequences($quete) as $sequence) {
            $actions = [];
            foreach ($sequence->getSequenceActions() as $sequenceAction) {
                $action = $sequenceAction->getAction();
                $actions[] = [
                    'id' => $action->getId(),
                    'type' => $action->getActionType()?->name,
                    'label' => $action->getName(),
                    'message' => $action->getMessage() ?? '',
                    'quantity' => $action->getQuantity() ?? 0,
                    'objetId' => $action->getObjet()?->getId() ?? 0,
                    'equipementId' => $action->getEquipement()?->getId() ?? 0,
                    'consommableId' => $action->getConsommable()?->getId() ?? 0,
                    'bossId' => $action->getBoss()?->getId() ?? 0,
                    'pnjId' => $action->getPnj()?->getId() ?? 0,
                    'carteId' => $action->getCarte()?->getId() ?? 0,
                    'effect' => $action->getEffect()?->value ?? '',
                    'effectParams' => $action->getEffectParams() !== null ? json_encode($action->getEffectParams()) : '',
                ];
            }

            $recompense = $sequence->getRecompense();
            $sequences[] = [
                'id' => $sequence->getId(),
                'nomSequence' => $sequence->getName(),
                'dialogueTitre' => $sequence->getDialogueTitre() ?? '',
                'dialogueContenu' => $sequence->getDialogueContenu() ?? '',
                'pnjId' => $sequence->getPnj()?->getId() ?? 0,
                'actions' => $actions,
                'recompense' => [
                    'money' => $recompense?->getMoney() ?? 0,
                    'experience' => $recompense?->getExperience() ?? 0,
                    'quantity' => $recompense?->getQuantity() ?? 0,
                    'objetId' => $recompense?->getObjet()?->getId() ?? 0,
                    'equipementId' => $recompense?->getEquipement()?->getId() ?? 0,
                    'consommableId' => $recompense?->getConsommable()?->getId() ?? 0,
                ],
            ];
        }

        return [
            'id' => $quete->getId(),
            'name' => $quete->getName(),
            'minimalLevel' => $quete->getMinimalLevel() ?? 0,
            'alignementId' => $quete->getAlignement()?->getId() ?? 0,
            'objetId' => $quete->getObjet()?->getId() ?? 0,
            'prerequisiteQueteId' => $quete->getQuete()?->getId() ?? 0,
            'sequences' => $sequences,
        ];
    }

    /** Tous les référentiels du QuestMaker en un seul appel. */
    public function getReferentiels(): array
    {
        $toIdName = fn (array $entities, string $getter): array => array_map(
            fn (object $e) => ['id' => $e->getId(), 'name' => $e->$getter()],
            $entities
        );

        return [
            'alignements' => $toIdName($this->alignementRepository->findAll(), 'getNom'),
            'objets' => $toIdName($this->objetRepository->findAll(), 'getName'),
            'equipements' => $toIdName($this->equipementRepository->findAll(), 'getNom'),
            'consommables' => $toIdName($this->consommableRepository->findAll(), 'getNom'),
            'bosses' => $toIdName($this->bossRepository->findAll(), 'getName'),
            'pnjs' => $toIdName($this->pnjRepository->findAll(), 'getName'),
            'cartes' => $toIdName($this->carteRepository->findAll(), 'getNom'),
            'quetes' => $this->listQuests(),
        ];
    }

    public function getActionTypeConfig(): array
    {
        return QuestActionTypeConfig::all();
    }

    /**
     * Crée (id absent/0) ou met à jour une quête complète. Renvoie le payload
     * éditeur rechargé après sauvegarde.
     */
    public function saveQuest(array $data): array
    {
        $questId = $this->entityManager->wrapInTransaction(function () use ($data): int {
            $quete = $this->upsertQuete($data);
            $this->upsertSequences($quete, $data['sequences'] ?? []);

            return $quete->getId();
        });

        return $this->getQuestForEditor($questId);
    }

    public function deleteQuest(int $questId): void
    {
        $quete = $this->queteRepository->find($questId);
        if ($quete === null) {
            throw new QuestException("Quête introuvable.");
        }

        $this->entityManager->wrapInTransaction(function () use ($quete): void {
            foreach ($this->userQueteRepository->findBy(['quete' => $quete]) as $userQuete) {
                $this->entityManager->remove($userQuete);
            }
            foreach ($quete->getSequences() as $sequence) {
                $this->removeSequence($sequence);
            }
            foreach ($quete->getPnjs() as $pnj) {
                $pnj->setQuete(null);
                $this->entityManager->persist($pnj);
            }
            $this->entityManager->remove($quete);
        });
    }

    private function upsertQuete(array $data): Quete
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new QuestException("Le nom de la quête est obligatoire.");
        }

        $questId = (int)($data['id'] ?? 0);
        if ($questId > 0) {
            $quete = $this->queteRepository->find($questId);
            if ($quete === null) {
                throw new QuestException("Quête introuvable.");
            }
        } else {
            $quete = new Quete();
        }

        $prerequisiteQueteId = (int)($data['prerequisiteQueteId'] ?? 0);
        if ($prerequisiteQueteId === $questId && $questId > 0) {
            throw new QuestException("Une quête ne peut pas être son propre prérequis.");
        }

        $minimalLevel = (int)($data['minimalLevel'] ?? 0);
        $quete->setName($name);
        $quete->setMinimalLevel($minimalLevel > 0 ? $minimalLevel : null);
        $quete->setAlignement($this->findOrNull($this->alignementRepository, (int)($data['alignementId'] ?? 0)));
        $quete->setObjet($this->findOrNull($this->objetRepository, (int)($data['objetId'] ?? 0)));
        $quete->setQuete($this->findOrNull($this->queteRepository, $prerequisiteQueteId));

        $this->entityManager->persist($quete);
        // Flush nécessaire pour disposer de l'id d'une quête neuve (toujours dans la transaction).
        $this->entityManager->flush();

        return $quete;
    }

    private function upsertSequences(Quete $quete, array $sequencesData): void
    {
        if ($sequencesData === []) {
            throw new QuestException("Une quête doit avoir au moins une séquence.");
        }

        $existingSequences = [];
        foreach ($quete->getSequences() as $sequence) {
            $existingSequences[$sequence->getId()] = $sequence;
        }

        // Positions temporairement négatives : un réordonnancement ne peut pas
        // violer la contrainte unique (quete_id, position) en cours de flush.
        foreach ($existingSequences as $sequence) {
            $sequence->setPosition(-$sequence->getPosition());
        }
        $this->entityManager->flush();

        $keptSequenceIds = [];
        $firstSequence = null;
        foreach (array_values($sequencesData) as $index => $sequenceData) {
            $sequence = $this->upsertSequence($quete, $sequenceData, $index + 1);
            $keptSequenceIds[] = $sequence->getId();
            $firstSequence ??= $sequence;
        }

        foreach ($existingSequences as $id => $sequence) {
            if (!in_array($id, $keptSequenceIds, true)) {
                $this->repointUserQuetes($sequence, $firstSequence);
                $this->removeSequence($sequence);
            }
        }

        $this->entityManager->flush();
    }

    private function upsertSequence(Quete $quete, array $data, int $position): Sequence
    {
        $sequenceId = (int)($data['id'] ?? 0);
        if ($sequenceId > 0) {
            $sequence = $this->entityManager->find(Sequence::class, $sequenceId);
            if ($sequence === null || $sequence->getQuete()?->getId() !== $quete->getId()) {
                throw new QuestException("Séquence introuvable dans cette quête.");
            }
        } else {
            $sequence = new Sequence();
            $sequence->setQuete($quete);
        }

        $pnj = $this->pnjRepository->find((int)($data['pnjId'] ?? 0));
        if ($pnj === null) {
            throw new QuestException("Chaque séquence doit être portée par un PNJ.");
        }

        $sequence->setName(trim((string)($data['nomSequence'] ?? '')));
        $sequence->setPosition($position);
        $sequence->setPnj($pnj);
        $sequence->setDialogueTitre(trim((string)($data['dialogueTitre'] ?? '')) ?: null);
        $sequence->setDialogueContenu(trim((string)($data['dialogueContenu'] ?? '')) ?: null);

        $this->entityManager->persist($sequence);
        $this->entityManager->flush();

        $this->upsertActions($sequence, $data['actions'] ?? []);
        $this->upsertRecompense($sequence, $data['recompense'] ?? []);

        return $sequence;
    }

    private function upsertActions(Sequence $sequence, array $actionsData): void
    {
        if ($actionsData === []) {
            throw new QuestException(
                "La séquence « {$sequence->getName()} » doit avoir au moins une action (le clic du joueur fait avancer la quête)."
            );
        }

        $existingBySequenceActionId = [];
        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            $existingBySequenceActionId[$sequenceAction->getAction()->getId()] = $sequenceAction;
        }

        $keptActionIds = [];
        foreach (array_values($actionsData) as $index => $actionData) {
            $actionId = (int)($actionData['id'] ?? 0);

            if ($actionId > 0 && isset($existingBySequenceActionId[$actionId])) {
                $sequenceAction = $existingBySequenceActionId[$actionId];
                $action = $sequenceAction->getAction();
            } else {
                $action = new Action();
                $sequenceAction = new SequenceAction();
                $sequenceAction->setSequence($sequence);
                $sequenceAction->setAction($action);
            }

            $this->hydrateAction($action, $actionData);
            $sequenceAction->setPosition($index + 1);

            $this->entityManager->persist($action);
            $this->entityManager->persist($sequenceAction);
            $keptActionIds[] = $action->getId();
        }

        foreach ($existingBySequenceActionId as $actionId => $sequenceAction) {
            if (!in_array($actionId, $keptActionIds, true)) {
                $this->removeSequenceAction($sequenceAction);
            }
        }

        $this->entityManager->flush();
    }

    private function hydrateAction(Action $action, array $data): void
    {
        $typeName = (string)($data['type'] ?? '');
        $type = null;
        foreach (ActionType::cases() as $case) {
            if ($case->name === $typeName) {
                $type = $case;
                break;
            }
        }
        if ($type === null || !$type->isImplemented()) {
            throw new QuestException("Type d'action inconnu ou non supporté : « {$typeName} ».");
        }

        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            throw new QuestException("Chaque action doit avoir un libellé (le texte du bouton).");
        }

        $action->setActionType($type);
        $action->setName($label);
        $action->setMessage(trim((string)($data['message'] ?? '')) ?: null);

        $quantity = (int)($data['quantity'] ?? 0);
        $action->setQuantity($quantity > 0 ? $quantity : null);

        $action->setObjet($this->findOrNull($this->objetRepository, (int)($data['objetId'] ?? 0)));
        $action->setEquipement($this->findOrNull($this->equipementRepository, (int)($data['equipementId'] ?? 0)));
        $action->setConsommable($this->findOrNull($this->consommableRepository, (int)($data['consommableId'] ?? 0)));
        $action->setBoss($this->findOrNull($this->bossRepository, (int)($data['bossId'] ?? 0)));
        $action->setPnj($this->findOrNull($this->pnjRepository, (int)($data['pnjId'] ?? 0)));
        $action->setCarte($this->findOrNull($this->carteRepository, (int)($data['carteId'] ?? 0)));

        if ($type === ActionType::SCRIPTED_EFFECT) {
            $effect = QuestEffect::tryFrom((string)($data['effect'] ?? ''));
            if ($effect === null) {
                throw new QuestException("L'action « {$label} » doit référencer un effet scripté valide.");
            }
            $action->setEffect($effect);
            $action->setEffectParams($this->parseEffectParams($data['effectParams'] ?? null, $label));
        } else {
            $action->setEffect(null);
            $action->setEffectParams(null);
        }

        $this->validateActionTarget($type, $action, $label);
    }

    /** La cible exigée par la config du type doit être renseignée. */
    private function validateActionTarget(ActionType $type, Action $action, string $label): void
    {
        $missing = match ($type) {
            ActionType::DONNER_OBJET, ActionType::POSSEDER_OBJET => $action->getObjet() === null,
            ActionType::DONNER_EQUIPEMENT => $action->getEquipement() === null,
            ActionType::DONNER_CONSOMMABLE => $action->getConsommable() === null,
            ActionType::BATTRE_BOSS => $action->getBoss() === null,
            ActionType::PARLER_PNJ => $action->getPnj() === null,
            ActionType::VISITER_CARTE => $action->getCarte() === null,
            ActionType::DONNER_OR, ActionType::ATTEINDRE_LEVEL => $action->getQuantity() === null,
            default => false,
        };

        if ($missing) {
            throw new QuestException("L'action « {$label} » ({$type->name}) est incomplète : cible ou quantité manquante.");
        }
    }

    private function parseEffectParams(mixed $raw, string $label): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new QuestException("Les paramètres d'effet de l'action « {$label} » ne sont pas un JSON valide.");
        }

        return $decoded;
    }

    private function upsertRecompense(Sequence $sequence, array $data): void
    {
        $money = (int)($data['money'] ?? 0);
        $experience = (int)($data['experience'] ?? 0);
        $quantity = (int)($data['quantity'] ?? 0);
        $objet = $this->findOrNull($this->objetRepository, (int)($data['objetId'] ?? 0));
        $equipement = $this->findOrNull($this->equipementRepository, (int)($data['equipementId'] ?? 0));
        $consommable = $this->findOrNull($this->consommableRepository, (int)($data['consommableId'] ?? 0));

        $isEmpty = $money <= 0 && $experience <= 0 && $objet === null && $equipement === null && $consommable === null;
        $recompense = $sequence->getRecompense();

        if ($isEmpty) {
            if ($recompense !== null) {
                $sequence->setRecompense(null);
                $this->entityManager->remove($recompense);
            }

            return;
        }

        if ($recompense === null) {
            $recompense = new Recompense();
            $recompense->setSequence($sequence);
            $sequence->setRecompense($recompense);
        }

        $recompense->setMoney($money > 0 ? $money : null);
        $recompense->setExperience($experience > 0 ? $experience : null);
        $recompense->setQuantity($quantity > 0 ? $quantity : null);
        $recompense->setObjet($objet);
        $recompense->setEquipement($equipement);
        $recompense->setConsommable($consommable);

        $this->entityManager->persist($recompense);
    }

    /**
     * Une séquence supprimée de la quête : les joueurs qui y étaient sont
     * remis sur la première séquence (progression conservée au mieux).
     */
    private function repointUserQuetes(Sequence $removed, ?Sequence $firstSequence): void
    {
        foreach ($this->userQueteRepository->findBy(['sequence' => $removed]) as $userQuete) {
            $userQuete->setSequence($firstSequence);
            $userQuete->setIsDone(false);
            $this->entityManager->persist($userQuete);
        }
    }

    private function removeSequence(Sequence $sequence): void
    {
        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            $this->removeSequenceAction($sequenceAction);
        }
        if ($sequence->getRecompense() !== null) {
            $this->entityManager->remove($sequence->getRecompense());
        }
        $this->entityManager->remove($sequence);
    }

    /** L'Action est supprimée avec sa liaison sauf si une case de carte l'utilise encore. */
    private function removeSequenceAction(SequenceAction $sequenceAction): void
    {
        $action = $sequenceAction->getAction();
        $this->entityManager->remove($sequenceAction);
        if ($action->getCarteCarreaus()->isEmpty()) {
            $this->entityManager->remove($action);
        }
    }

    private function findOrNull(object $repository, int $id): ?object
    {
        return $id > 0 ? $repository->find($id) : null;
    }

    /** @return Sequence[] triées par position */
    private function getOrderedSequences(Quete $quete): array
    {
        $sequences = $quete->getSequences()->toArray();
        usort($sequences, fn (Sequence $a, Sequence $b) => $a->getPosition() <=> $b->getPosition());

        return $sequences;
    }
}
