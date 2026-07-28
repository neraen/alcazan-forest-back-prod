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
use App\Repository\ActionRepository;
use App\Repository\AlignementRepository;
use App\Repository\BossRepository;
use App\Repository\CarteRepository;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\MonstreRepository;
use App\Repository\ObjetRepository;
use App\Repository\PnjRepository;
use App\Repository\QueteRepository;
use App\Repository\RecetteRepository;
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
    /** Cible de branchement « terminer la quête » (au lieu d'une séquence). */
    private const QUEST_END_KEY = '__END__';

    public function __construct(
        private readonly QueteRepository $queteRepository,
        private readonly UserQueteRepository $userQueteRepository,
        private readonly ActionRepository $actionRepository,
        private readonly PnjRepository $pnjRepository,
        private readonly AlignementRepository $alignementRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EquipementRepository $equipementRepository,
        private readonly ConsommableRepository $consommableRepository,
        private readonly BossRepository $bossRepository,
        private readonly CarteRepository $carteRepository,
        private readonly MonstreRepository $monstreRepository,
        private readonly RecetteRepository $recetteRepository,
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
                    'monstreId' => $action->getMonstre()?->getId() ?? 0,
                    'recetteId' => $action->getRecette()?->getId() ?? 0,
                    'pnjId' => $action->getPnj()?->getId() ?? 0,
                    'carteId' => $action->getCarte()?->getId() ?? 0,
                    'karma' => $action->getKarma() ?? 0,
                    'effect' => $action->getEffect()?->value ?? '',
                    'effectParams' => $action->getEffectParams() !== null ? json_encode($action->getEffectParams()) : '',
                    // Branchement : clé de la séquence cible, '__END__' pour terminer, '' pour le linéaire.
                    'nextSequenceKey' => $this->serializeBranchKey($action),
                    'recompense' => $this->serializeRecompense($action->getRecompense()),
                ];
            }

            $sequences[] = [
                'id' => $sequence->getId(),
                // La clé stable d'une séquence existante est son id (les nouvelles
                // séquences reçoivent une clé temporaire générée par le front).
                'clientKey' => (string)$sequence->getId(),
                'nomSequence' => $sequence->getName(),
                'dialogueTitre' => $sequence->getDialogueTitre() ?? '',
                'dialogueContenu' => $sequence->getDialogueContenu() ?? '',
                'pnjId' => $sequence->getPnj()?->getId() ?? 0,
                'actions' => $actions,
            ];
        }

        return [
            'id' => $quete->getId(),
            'name' => $quete->getName(),
            'introduction' => $quete->getIntroduction() ?? '',
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
            'monstres' => $toIdName($this->monstreRepository->findAll(), 'getName'),
            'recettes' => $toIdName($this->recetteRepository->findAll(), 'getNom'),
            // Les ressources sont les objets rattachés à un métier (lot 1 de l'artisanat) :
            // proposer tout le catalogue d'objets ferait écrire des quêtes de cueillette
            // sur des objets qu'aucune case ne fait jamais récolter.
            'ressources' => $toIdName(
                array_values(array_filter($this->objetRepository->findAll(), fn ($objet) => $objet->getMetier() !== null)),
                'getName'
            ),
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

        // Relecture depuis la BASE et non depuis les entités en mémoire (même piège que
        // dans les autres services d'édition, cf. CLAUDE.md) : les séquences et actions
        // d'une quête NEUVE ont été persistées sans être ajoutées aux collections
        // inverses, qui sont donc vides. Sans ce `clear()`, créer une quête renvoyait
        // `sequences: []` — et le front, qui fait `reset(saved)`, vidait à l'écran le
        // travail qu'il venait d'enregistrer.
        $this->entityManager->clear();

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
                if ($pnj->getType() === 'quest') {
                    $pnj->setType('action');
                }
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
        $quete->setIntroduction(trim((string)($data['introduction'] ?? '')) ?: null);
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

        // Passe 1 : upsert des séquences, actions et récompenses. On mémorise
        // la correspondance clientKey -> Séquence et le câblage de branchement
        // à résoudre en passe 2 (les cibles peuvent être des séquences créées
        // plus loin dans le même payload, sans id au moment où on lit l'action).
        $keptSequenceIds = [];
        $firstSequence = null;
        $usedPnjs = [];
        $clientKeyToSequence = [];
        $branchWiring = [];
        foreach (array_values($sequencesData) as $index => $sequenceData) {
            $sequence = $this->upsertSequence($quete, $sequenceData, $index + 1, $branchWiring);
            $keptSequenceIds[] = $sequence->getId();
            $firstSequence ??= $sequence;
            $pnj = $sequence->getPnj();
            $usedPnjs[$pnj->getId()] = $pnj;
            $clientKeyToSequence[(string)($sequenceData['clientKey'] ?? $sequence->getId())] = $sequence;
        }

        // Passe 2 : résolution des branchements (toujours réinitialisés depuis
        // le payload, cibles limitées aux séquences de cette quête).
        foreach ($branchWiring as ['action' => $action, 'key' => $key]) {
            $this->applyBranch($action, $key, $clientKeyToSequence);
        }
        $this->entityManager->flush();

        foreach ($existingSequences as $id => $sequence) {
            if (!in_array($id, $keptSequenceIds, true)) {
                $this->repointUserQuetes($sequence, $firstSequence);
                // Détache les branchements pointant vers cette séquence avant sa
                // suppression (contrainte FK next_sequence_id).
                $this->detachBranchesTargeting($sequence);
                $this->removeSequence($sequence);
            }
        }

        $this->syncPnjQuestLinks($quete, $usedPnjs);

        $this->entityManager->flush();
    }

    /** Applique une clé de branchement d'éditeur à une action. */
    private function applyBranch(Action $action, string $key, array $clientKeyToSequence): void
    {
        if ($key === self::QUEST_END_KEY) {
            $action->setEndsQuest(true);
            $action->setNextSequence(null);
        } else {
            $action->setEndsQuest(null);
            // Clé inconnue (cible supprimée) => retour au linéaire par défaut.
            $action->setNextSequence($clientKeyToSequence[$key] ?? null);
        }

        $this->entityManager->persist($action);
    }

    /** Annule les branchements de toutes les actions vers $target (avant suppression). */
    private function detachBranchesTargeting(Sequence $target): void
    {
        foreach ($this->actionRepository->findBy(['nextSequence' => $target]) as $action) {
            $action->setNextSequence(null);
            $this->entityManager->persist($action);
        }
        $this->entityManager->flush();
    }

    /**
     * Synchronise le lien retour PNJ -> quête. L'interaction (`GET /api/pnj/
     * interaction`) route vers la vue quête via `pnj.type` et récupère la quête
     * portée via `pnj.quete` : chaque PNJ qui tient une séquence doit donc être
     * marqué `type = 'quest'` et pointer sur cette quête. Les PNJ retirés de la
     * quête sont détachés pour ne pas rester bloqués sur « ce PNJ ne porte
     * aucune quête ».
     *
     * @param array<int, \App\Entity\Pnj> $usedPnjs PNJ porteurs d'une séquence, indexés par id
     */
    private function syncPnjQuestLinks(Quete $quete, array $usedPnjs): void
    {
        // Détache les PNJ qui ne portent plus aucune séquence de cette quête.
        foreach ($quete->getPnjs()->toArray() as $pnj) {
            if (!isset($usedPnjs[$pnj->getId()])) {
                $pnj->setQuete(null);
                if ($pnj->getType() === 'quest') {
                    $pnj->setType('action');
                }
                $this->entityManager->persist($pnj);
            }
        }

        // (Re)lie chaque PNJ porteur d'une séquence à cette quête.
        foreach ($usedPnjs as $pnj) {
            $pnj->setQuete($quete);
            $pnj->setType('quest');
            $this->entityManager->persist($pnj);
        }
    }

    private function upsertSequence(Quete $quete, array $data, int $position, array &$branchWiring): Sequence
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

        $this->upsertActions($sequence, $data['actions'] ?? [], $branchWiring);

        return $sequence;
    }

    private function upsertActions(Sequence $sequence, array $actionsData, array &$branchWiring): void
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
            $this->entityManager->flush();

            // Récompense par action (= par branche/choix) et câblage de branchement
            // différé (la cible peut ne pas encore exister à ce stade).
            $this->upsertRecompense($action, $actionData['recompense'] ?? []);
            $branchWiring[] = ['action' => $action, 'key' => (string)($actionData['nextSequenceKey'] ?? '')];

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

        // Le karma peut être NÉGATIF : c'est tout l'intérêt du champ. Il n'est donc
        // pas filtré comme une quantité (`> 0`), seul 0 vaut « ce choix n'engage rien ».
        $karma = (int)($data['karma'] ?? 0);
        $action->setKarma($karma !== 0 ? $karma : null);

        $action->setObjet($this->findOrNull($this->objetRepository, (int)($data['objetId'] ?? 0)));
        $action->setEquipement($this->findOrNull($this->equipementRepository, (int)($data['equipementId'] ?? 0)));
        $action->setConsommable($this->findOrNull($this->consommableRepository, (int)($data['consommableId'] ?? 0)));
        $action->setBoss($this->findOrNull($this->bossRepository, (int)($data['bossId'] ?? 0)));
        $action->setMonstre($this->findOrNull($this->monstreRepository, (int)($data['monstreId'] ?? 0)));
        $action->setRecette($this->findOrNull($this->recetteRepository, (int)($data['recetteId'] ?? 0)));
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
            // Les types comptables exigent la cible ET la quantité : sans quantité, la
            // condition serait « au moins un » sans que l'auteur l'ait demandé.
            ActionType::BATTRE_MONSTRE => $action->getMonstre() === null || $action->getQuantity() === null,
            ActionType::FABRIQUER_OBJET => $action->getRecette() === null || $action->getQuantity() === null,
            ActionType::RECOLTER_RESSOURCE => $action->getObjet() === null || $action->getQuantity() === null,
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

    private function upsertRecompense(Action $action, array $data): void
    {
        $money = (int)($data['money'] ?? 0);
        $experience = (int)($data['experience'] ?? 0);
        $quantity = (int)($data['quantity'] ?? 0);
        $objet = $this->findOrNull($this->objetRepository, (int)($data['objetId'] ?? 0));
        $equipement = $this->findOrNull($this->equipementRepository, (int)($data['equipementId'] ?? 0));
        $consommable = $this->findOrNull($this->consommableRepository, (int)($data['consommableId'] ?? 0));

        $isEmpty = $money <= 0 && $experience <= 0 && $objet === null && $equipement === null && $consommable === null;
        $recompense = $action->getRecompense();

        if ($isEmpty) {
            if ($recompense !== null) {
                $action->setRecompense(null);
                $this->entityManager->remove($recompense);
            }

            return;
        }

        if ($recompense === null) {
            $recompense = new Recompense();
            $recompense->setAction($action);
            $action->setRecompense($recompense);
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
        $this->entityManager->remove($sequence);
    }

    /** L'Action est supprimée avec sa liaison sauf si une case de carte l'utilise encore. */
    private function removeSequenceAction(SequenceAction $sequenceAction): void
    {
        $action = $sequenceAction->getAction();
        $this->entityManager->remove($sequenceAction);
        if ($action->getCarteCarreaus()->isEmpty()) {
            // La récompense (OneToOne côté action) part avec l'action.
            if ($action->getRecompense() !== null) {
                $this->entityManager->remove($action->getRecompense());
            }
            $action->setNextSequence(null);
            $this->entityManager->remove($action);
        }
    }

    private function findOrNull(object $repository, int $id): ?object
    {
        return $id > 0 ? $repository->find($id) : null;
    }

    /** Clé de branchement d'une action pour l'éditeur : '__END__' | id cible | ''. */
    private function serializeBranchKey(Action $action): string
    {
        if ($action->getEndsQuest() === true) {
            return self::QUEST_END_KEY;
        }

        $target = $action->getNextSequence();

        return $target !== null ? (string)$target->getId() : '';
    }

    private function serializeRecompense(?Recompense $recompense): array
    {
        return [
            'money' => $recompense?->getMoney() ?? 0,
            'experience' => $recompense?->getExperience() ?? 0,
            'quantity' => $recompense?->getQuantity() ?? 0,
            'objetId' => $recompense?->getObjet()?->getId() ?? 0,
            'equipementId' => $recompense?->getEquipement()?->getId() ?? 0,
            'consommableId' => $recompense?->getConsommable()?->getId() ?? 0,
        ];
    }

    /** @return Sequence[] triées par position */
    private function getOrderedSequences(Quete $quete): array
    {
        $sequences = $quete->getSequences()->toArray();
        usort($sequences, fn (Sequence $a, Sequence $b) => $a->getPosition() <=> $b->getPosition());

        return $sequences;
    }
}
