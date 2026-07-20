<?php

namespace App\service;

use App\Entity\Action;
use App\Entity\Pnj;
use App\Entity\Quete;
use App\Entity\Sequence;
use App\Entity\User;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Exception\QuestException;
use App\Exception\UnsupportedQuestActionException;
use App\Repository\ActionRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
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
 */
class QuestProgressionService
{
    public function __construct(
        private readonly SequenceRepository $sequenceRepository,
        private readonly UserQueteRepository $userQueteRepository,
        private readonly ActionRepository $actionRepository,
        private readonly InventaireRepository $inventaireRepository,
        private readonly InventaireObjetRepository $inventaireObjetRepository,
        private readonly InventaireEquipementRepository $inventaireEquipementRepository,
        private readonly InventaireConsommableRepository $inventaireConsommableRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        private readonly UserBossRepository $userBossRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly InventaireService $inventaireService,
        private readonly LevelingService $levelingService,
        private readonly QuestEffectRegistry $effectRegistry,
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
                'step' => $this->buildStepPayload($userQuete->getSequence()),
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

            return $this->buildResponse('step', $questInfo, $this->buildStepPayload($existing->getSequence()));
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
        $this->entityManager->persist($userQuete);
        $this->entityManager->flush();

        return $this->buildResponse('step', $questInfo, $this->buildStepPayload($firstSequence));
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

            if (!$this->isActionConditionMet($action, $user)) {
                return $this->buildResponse(
                    'blocked',
                    $questInfo,
                    $this->buildStepPayload($sequence),
                    blockedMessages: [$this->blockedMessage($action)]
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

            // Dialogue autonome (PNJ "action") : pas de progression à gérer.
            if ($quete === null) {
                return $this->buildResponse(
                    'done',
                    null,
                    $this->buildStepPayload($sequence),
                    feedbackMessages: $effectMessages,
                    needRefresh: $needRefresh
                );
            }

            ['rewards' => $rewards, 'playerXp' => $playerXp] = $this->giveSequenceReward($user, $sequence);
            $needRefresh = $needRefresh || $rewards !== [];

            $nextSequence = $this->sequenceRepository->findOneBy([
                'quete' => $quete,
                'position' => $sequence->getPosition() + 1,
            ]);

            if ($nextSequence === null) {
                $userQuete->setIsDone(true);
                $this->entityManager->persist($userQuete);

                return $this->buildResponse(
                    'done',
                    $questInfo,
                    rewards: $rewards,
                    feedbackMessages: $effectMessages,
                    needRefresh: $needRefresh,
                    playerXp: $playerXp
                );
            }

            $userQuete->setSequence($nextSequence);
            $this->entityManager->persist($userQuete);

            return $this->buildResponse(
                'step',
                $questInfo,
                $this->buildStepPayload($nextSequence),
                rewards: $rewards,
                feedbackMessages: $effectMessages,
                needRefresh: $needRefresh,
                playerXp: $playerXp
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

        return $this->entityManager->wrapInTransaction(
            fn (): array => $this->effectRegistry->execute($effect, $action->getEffectParams() ?? [], $user)
        );
    }

    /** Payload d'une étape : dialogue en paragraphes + boutons typés. Aucun HTML. */
    public function buildStepPayload(?Sequence $sequence): ?array
    {
        if ($sequence === null) {
            return null;
        }

        $actions = [];
        foreach ($sequence->getSequenceActions() as $sequenceAction) {
            $action = $sequenceAction->getAction();
            $actions[] = [
                'actionId' => $action->getId(),
                'type' => $action->getActionType()?->name,
                'label' => $action->getName(),
            ];
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

    /** Vérifie la condition d'une action sans rien consommer. */
    public function isActionConditionMet(Action $action, User $user): bool
    {
        return match ($action->getActionType()) {
            ActionType::DONNER_OBJET,
            ActionType::POSSEDER_OBJET => $this->userHasObjet($user, $action->getObjet()?->getId() ?? 0, max(1, (int)$action->getQuantity())),
            ActionType::DONNER_OR => $user->getMoney() >= $action->getQuantity(),
            ActionType::DONNER_EQUIPEMENT => $this->verifyEquipementInventaire($action, $user),
            ActionType::DONNER_CONSOMMABLE => $this->verifyConsommableInventaire($action, $user),
            ActionType::ATTEINDRE_LEVEL => ($this->niveauJoueurRepository->getPlayerLevel($user->getId()) ?? 1) >= $action->getQuantity(),
            ActionType::BATTRE_BOSS => $this->verifyBossKilled($action, $user),
            ActionType::PARLER_PNJ => $this->verifyPnjProximity($action, $user),
            ActionType::VISITER_CARTE => $this->verifyCarteVisited($action, $user),
            ActionType::SCRIPTED_EFFECT, ActionType::PASSER_DIALOGUE => true,
            default => throw new UnsupportedQuestActionException($action->getActionType()),
        };
    }

    /** Consomme le coût d'une action DONNER_* (après isActionConditionMet). */
    private function consumeActionCost(Action $action, User $user): void
    {
        switch ($action->getActionType()) {
            case ActionType::DONNER_OR:
                $user->setMoney($user->getMoney() - $action->getQuantity());
                $this->entityManager->persist($user);
                break;
            case ActionType::DONNER_OBJET:
                $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
                $line = $this->inventaireObjetRepository->findOneBy(['inventaire' => $inventaire, 'objet' => $action->getObjet()]);
                $this->decrementInventoryLine($line, max(1, (int)$action->getQuantity()));
                break;
            case ActionType::DONNER_EQUIPEMENT:
                $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
                $line = $this->inventaireEquipementRepository->findOneBy(['inventaire' => $inventaire, 'equipement' => $action->getEquipement()]);
                $this->decrementInventoryLine($line, max(1, (int)$action->getQuantity()));
                break;
            case ActionType::DONNER_CONSOMMABLE:
                $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
                $line = $this->inventaireConsommableRepository->findOneBy(['inventaire' => $inventaire, 'consommable' => $action->getConsommable()]);
                $this->decrementInventoryLine($line, max(1, (int)$action->getQuantity()));
                break;
            default:
                break;
        }
    }

    /**
     * Donne la récompense de la séquence. Renvoie :
     *  - 'rewards'  : items obtenus [{type, label, quantity}] pour le feedback front ;
     *  - 'playerXp' : {experience, level, experienceMax} si de l'XP a été donnée
     *    (pour que le front rafraîchisse la barre/le niveau sans rechargement), sinon null.
     */
    private function giveSequenceReward(User $user, Sequence $sequence): array
    {
        $recompense = $sequence->getRecompense();
        if ($recompense === null) {
            return ['rewards' => [], 'playerXp' => null];
        }

        $rewards = [];
        $playerXp = null;
        $quantity = max(1, (int)$recompense->getQuantity());

        if ($recompense->getEquipement() !== null) {
            $this->inventaireService->addEquipementToUserInventaire($user->getId(), $recompense->getEquipement()->getId());
            $rewards[] = ['type' => 'equipement', 'label' => $recompense->getEquipement()->getNom(), 'quantity' => 1];
        }

        if ($recompense->getConsommable() !== null) {
            $this->inventaireService->addConsommableToUserInventaire($user->getId(), $recompense->getConsommable()->getId(), $quantity);
            $rewards[] = ['type' => 'consommable', 'label' => $recompense->getConsommable()->getNom(), 'quantity' => $quantity];
        }

        if ($recompense->getObjet() !== null) {
            $this->inventaireService->addObjetToUserInventaire($user->getId(), $recompense->getObjet()->getId(), $quantity);
            $rewards[] = ['type' => 'objet', 'label' => $recompense->getObjet()->getName(), 'quantity' => $quantity];
        }

        if ($recompense->getMoney() !== null && $recompense->getMoney() > 0) {
            $this->inventaireService->giveMoneyToUser($user, $recompense->getMoney());
            $rewards[] = ['type' => 'or', 'label' => "pièces d'or", 'quantity' => $recompense->getMoney()];
        }

        if ($recompense->getExperience() !== null && $recompense->getExperience() > 0) {
            $playerXp = $this->levelingService->giveExperienceToAPlayer($recompense->getExperience(), $user->getId());
            $rewards[] = ['type' => 'experience', 'label' => "points d'expérience", 'quantity' => $recompense->getExperience()];
        }

        return ['rewards' => $rewards, 'playerXp' => $playerXp];
    }

    private function buildResponse(
        string $status,
        ?array $questInfo,
        ?array $step = null,
        array $rewards = [],
        array $blockedMessages = [],
        array $feedbackMessages = [],
        bool $needRefresh = false,
        ?array $playerXp = null
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

    private function blockedMessage(Action $action): string
    {
        $message = trim((string)$action->getMessage());
        if ($message !== '') {
            return $message;
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

    private function userHasObjet(User $user, int $objetId, int $quantity): bool
    {
        if ($objetId <= 0) {
            return false;
        }
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        $line = $this->inventaireObjetRepository->findOneBy(['inventaire' => $inventaire, 'objet' => $objetId]);

        return $line !== null && $line->getQuantity() >= $quantity;
    }

    private function verifyEquipementInventaire(Action $action, User $user): bool
    {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        $line = $this->inventaireEquipementRepository->findOneBy(['inventaire' => $inventaire, 'equipement' => $action->getEquipement()]);

        return $line !== null && $line->getQuantity() >= max(1, (int)$action->getQuantity());
    }

    private function verifyConsommableInventaire(Action $action, User $user): bool
    {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        $line = $this->inventaireConsommableRepository->findOneBy(['inventaire' => $inventaire, 'consommable' => $action->getConsommable()]);

        return $line !== null && $line->getQuantity() >= max(1, (int)$action->getQuantity());
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

    private function decrementInventoryLine(?object $line, int $quantity): void
    {
        if ($line === null) {
            throw new QuestException("Ressource introuvable dans l'inventaire.");
        }

        $newQuantity = $line->getQuantity() - $quantity;
        if ($newQuantity <= 0) {
            $this->entityManager->remove($line);
        } else {
            $line->setQuantity($newQuantity);
            $this->entityManager->persist($line);
        }
    }
}
