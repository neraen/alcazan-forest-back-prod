<?php

namespace App\service;

use App\Entity\Action;
use App\Entity\InventaireConsommable;
use App\Entity\InventaireEquipement;
use App\Entity\Quete;
use App\Entity\Sequence;
use App\Entity\User;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Enum\ConditionalAction;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\RecompenseRepository;
use App\Repository\SequenceActionRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserQueteRepository;
use Doctrine\ORM\EntityManagerInterface;

class QuestService
{

    private string $questMessage = "";

    public function __construct(
        private readonly InventaireService $inventaireService,
        private readonly LevelingService $levelingService,
        private readonly SequenceActionRepository $sequenceActionRepository,
        private readonly SequenceRepository $sequenceRepository,
        private readonly UserQueteRepository $userQueteRepository,
        private readonly InventaireRepository $inventaireRepository,
        private readonly InventaireObjetRepository $inventaireObjetRepository,
        private readonly InventaireEquipementRepository $inventaireEquipementRepository,
        private readonly InventaireConsommableRepository $inventaireConsommableRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        private readonly RecompenseRepository $recompenseRepository,
        private readonly EntityManagerInterface $entityManager
    ){}

    public function verifySequenceCondition(int $sequenceId, User $user): array {
        $isConditionValid = true;
        $sequenceActions = $this->sequenceActionRepository->findBy(['sequence' => $sequenceId]);
        $this->questMessage = "";

        foreach ($sequenceActions as $sequenceAction){
            $action = $sequenceAction->getAction();

            switch ($action->getActionType()->getId()){
                case ActionType::DONNER_OBJET->value:
                    $isConditionActionValid = $this->verifyObjetInventaire($action, $user);
                    $this->questMessage .= $isConditionActionValid ? '' : $action->getMessage()."<br />";
                    break;
                case ActionType::DONNER_OR->value:
                    $isConditionActionValid = $this->verifyUserGold($action, $user);
                    $this->questMessage .= $isConditionActionValid ? '' : $action->getMessage()."<br />";
                    break;
                case ActionType::DONNER_EQUIPEMENT->value:
                    $isConditionActionValid = $this->verifyEquipementInventaire($action, $user);
                    $this->questMessage .= $isConditionActionValid ? '' : $action->getMessage()."<br />";
                    break;
                case ActionType::DONNER_CONSOMMABLE->value:
                    $isConditionActionValid = $this->verifyConsommableInventaire($action, $user);
                    $this->questMessage .= $isConditionActionValid ? '' : $action->getMessage()."<br />";
                    break;
                case ActionType::ATTEINDRE_LEVEL->value:
                    $isConditionActionValid = $this->verifyUserLevel($action, $user);
                    $this->questMessage .= $isConditionActionValid ? '' : $action->getMessage()."<br />";
                    break;
                case ActionType::JSON->value:
                case ActionType::PASSER_DIALOGUE->value:
                    $isConditionActionValid = true;
                    $this->questMessage .= "<br />";
                    break;


            }

            if(!$isConditionActionValid){
                $isConditionValid = false;
            }
        }

        $sequenceConditionState = [
            'isConditionValid' => $isConditionValid,
            'messages' => $this->questMessage
        ];

        $this->questMessage = "";
        return $sequenceConditionState;
    }

    private function verifyObjetInventaire(Action $action, User $user): bool {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        $inventaireObjet = $this->inventaireObjetRepository->findOneBy(['inventaire' => $inventaire, 'objet' => $action->getObjet()]);

        return $inventaireObjet !== null && ($inventaireObjet->getQuantity() >= $action->getQuantity());
    }

    private function verifyUserGold(Action $action, User $user): bool {
        return $user->getMoney() >= $action->getQuantity();
    }

    private function verifyEquipementInventaire(Action $action, User $user): bool {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        $inventaireEquipement = $this->inventaireEquipementRepository->findOneBy(['inventaire' => $inventaire, 'equipement' => $action->getEquipement()]);

        return $inventaireEquipement !== null && ($inventaireEquipement->getQuantity() >= $action->getQuantity());
    }

    private function verifyConsommableInventaire(Action $action, User $user): bool {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        $inventaireConsommable = $this->inventaireConsommableRepository->findOneBy(['inventaire' => $inventaire, 'consommable' => $action->getConsommable()]);

        return $inventaireConsommable !== null && ($inventaireConsommable->getQuantity() >= $action->getQuantity());
    }

    private function verifyUserLevel(Action $action, User $user): bool {
        $niveauJoueur = $this->niveauJoueurRepository->getPlayerLevel($user->getId());
        return $niveauJoueur >= $action->getQuantity();
    }

    public function validateQuestAction(User $user, int $sequenceId): ?UserQuete {
        $this->giveRecompenseToUser($user, $sequenceId);
        $sequence = $this->sequenceRepository->find($sequenceId);
        if($sequence->getIsLast()){
            $this->setQuestDone($user, $sequence);
            return null;
        }else{
            return $this->setNextSequence($user->getId(), $sequence->getId());
        }
    }

    public function setNextSequence(int $userId, int $sequenceId): ?UserQuete {
        $actualSequence = $this->sequenceRepository->find($sequenceId);
        if(!$actualSequence->getIsLast()){
            $nextPosition = $actualSequence->getPosition() + 1;
            $nextSequence = $this->sequenceRepository->findOneBy(['position' => $nextPosition, 'quete' => $actualSequence->getQuete()]);
            $userQuete = $this->userQueteRepository->findOneBy(['user' => $userId, 'quete' => $actualSequence->getQuete()]);
            $userQuete->setSequence($nextSequence);

            $this->entityManager->persist($userQuete);
            $this->entityManager->flush();
            return $userQuete;
        }
        return null;
    }

    public function checkSequenceHaveConditionalAction(array $actions): bool {

        $hasConditionalAction = false;
        foreach ($actions as $action){
            if(in_array($action['actionTypeId'], ConditionalAction::getValues())){
                $hasConditionalAction = true;
            }
        }

        return $hasConditionalAction;
    }

    public function setQuestDone(User $user, Sequence $sequence): void {
        $userQueteEntity = $this->userQueteRepository->findOneBy(['user' => $user, 'sequence' => $sequence]);
        $userQueteEntity->setIsDone(true);
        $this->entityManager->persist($userQueteEntity);
        $this->entityManager->flush();
    }

    /** todo: externaliser les messages => mieux, les gérer en front en renvoyant un tableau de récompenses */
    public function giveRecompenseToUser(User $user, int $sequenceId): string {
        $userId = $user->getId();
        $recompenseEntity = $this->recompenseRepository->findOneBy(['sequence' => $sequenceId]);
        $message = "";
        if($recompenseEntity->getEquipement()){
            $idEquipement = $recompenseEntity->getEquipement()->getId();
            $this->inventaireService->addEquipementToUserInventaire($userId, $idEquipement);
            $message .= "Vous recevez l'équipement {$recompenseEntity->getEquipement()->getNom()}";
        }

        if($recompenseEntity->getConsommable()){
            $idConsommable = $recompenseEntity->getConsommable()->getId();
            $this->inventaireService->addConsommableToUserInventaire($userId, $idConsommable, $recompenseEntity->getQuantity());
            $message .= "Vous recevez les potions {$recompenseEntity->getConsommable()->getNom()}";
        }

        if($recompenseEntity->getObjet()){
            $idObjet = $recompenseEntity->getObjet()->getId();
            $this->inventaireService->addConsommableToUserInventaire($userId, $idObjet, $recompenseEntity->getQuantity());
            $message .= "Vous recevez les potions {$recompenseEntity->getObjet()->getName()}";
        }

        $moneyRecompense = $recompenseEntity->getMoney();
        if(!is_null($moneyRecompense) && $moneyRecompense > 0){
            $this->inventaireService->giveMoneyToUser($user, $moneyRecompense);
            $message .= "Vous gagnez $moneyRecompense pièces d'Or.";
        }

        $experienceRecompense = $recompenseEntity->getExperience();
        if(!is_null($experienceRecompense) && $experienceRecompense > 0){
            $this->levelingService->giveExperienceToAPlayer($experienceRecompense, $userId);
            $message .= "Vous gagnez $experienceRecompense points d'expériences.";
        }

        return $message;
    }
}