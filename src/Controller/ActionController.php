<?php

namespace App\Controller;

use App\DTO\Action\ActionAtteindreLevelDTO;
use App\DTO\Action\ActionDonnerObjetDTO;
use App\DTO\Action\ActionPasserDialogueDTO;
use App\Repository\ActionRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SequenceActionRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserQueteRepository;
use App\service\QuestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route("/api/action", name:"api_")]
class ActionController extends AbstractController
{
    #[Route("/passer/dialogue", name:"action_passer_dialogue")]
    public function actionPasserDialogue(
        #[MapRequestPayload] ActionPasserDialogueDTO $actionPasserDialogueDTO,
        QuestService $questService,
        SequenceRepository $sequenceRepository,
        SequenceActionRepository $sequenceActionRepository,
        UserQueteRepository $userQueteRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $actualSequence = $sequenceRepository->find($actionPasserDialogueDTO->getSequenceId());
        if(!$actualSequence->getIsLast()){
            $user = $this->getUser();
            $quete = $actualSequence->getQuete();
            $nextPosition = $actualSequence->getPosition() + 1;
            $nextSequence = $sequenceRepository->findOneBy(['position' => $nextPosition, 'quete' => $quete]);
            $userQuete = $userQueteRepository->findOneBy(['user' => $user, 'quete' => $quete]);
            $userQuete->setSequence($nextSequence);
            $entityManager->persist($userQuete);
            $entityManager->flush();
        }

        $dialogue = $nextSequence->getDialogue()->getContenu();
        $questService->validateQuestAction($user,  $actionPasserDialogueDTO->getSequenceId());

        if($nextSequence->getHasAction()){
            $actions = $sequenceActionRepository->getAllActionsBySequence($nextSequence->getId());
        }
        $hasConditionalAction = $questService->checkSequenceHaveConditionalAction($actions);

        $questData = [
            'title' => $quete->getName(),
            'dialogue' => $dialogue ?? '',
            'actions' => $actions ?? [],
            'sequenceId' => $nextSequence->getId(),
            'hasConditionalAction' => $hasConditionalAction,
            'respectSequenceConditions' => true
        ];

        return new Response(json_encode($questData));
    }

    #[Route("/donner/objet", name:"action_donner_objet")]
    public function actionDonnerObjet(
        #[MapRequestPayload] ActionDonnerObjetDTO $actionDonnerObjetDTO,
        QuestService $questService,
        ActionRepository $actionRepository,
        SequenceActionRepository $sequenceActionRepository,
        InventaireObjetRepository $inventaireObjetRepository,
        InventaireRepository $inventaireRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        $action = $actionRepository->find($actionDonnerObjetDTO->getActionId());
        $inventaire = $inventaireRepository->findOneBy(['user' => $user]);
        $inventaireObjet = $inventaireObjetRepository->findOneBy(['inventaire' => $inventaire, 'objet' => $action->getObjet()]);

        if($inventaireObjet !== null && $inventaireObjet->getQuantity() >= $action->getQuantity()){
            $newQuantityInInventaire = $inventaireObjet->getQuantity() - $action->getQuantity();
            if($newQuantityInInventaire === 0){
                $entityManager->remove($inventaireObjet);
            }else{
                $inventaireObjet->setQuantity($newQuantityInInventaire);
                $entityManager->persist($inventaireObjet);
            }
            $entityManager->flush();

            $userQuete = $questService->validateQuestAction($user, $actionDonnerObjetDTO->getSequenceId());
            $questService->validateQuestAction($user, $actionDonnerObjetDTO->getSequenceId());

            if($userQuete->getSequence()->getHasAction()){
                $actions = $sequenceActionRepository->getAllActionsBySequence($userQuete->getSequence()->getId());
            }
            $hasConditionalAction = $questService->checkSequenceHaveConditionalAction($actions);

            $response = [
                'title' => $userQuete->getQuete()->getName(),
                'dialogue' => $userQuete->getSequence()->getDialogue()->getContenu() ?? '',
                'actions' => $actions ?? [],
                'sequenceId' => $userQuete->getSequence()->getId(),
                'hasConditionalAction' => $hasConditionalAction,
                'respectSequenceConditions' => true
            ];
        }else{
            $response = ["Erreur" => "vous n'avez pas les objets nécéssaire pour réaliser l'action"];
        }


        return new Response(json_encode($response));
    }

    #[Route("/atteindre/level", name:"action_atteindre_level")]
    public function actionAtteindreLevel(
        #[MapRequestPayload] ActionAtteindreLevelDTO $actionAtteindreLevelDTO,
        QuestService $questService,
        SequenceActionRepository $sequenceActionRepository,
        ActionRepository $actionRepository,
        NiveauJoueurRepository $niveauJoueurRepository
    ): Response {
        $user = $this->getUser();
        $action = $actionRepository->find($actionAtteindreLevelDTO->getActionId());
        $userLevel = $niveauJoueurRepository->getPlayerLevel($user->getId());

        if($userLevel >= $action->getQuantity()){
            $userQuete = $questService->validateQuestAction($user, $actionAtteindreLevelDTO->getSequenceId());

            if($userQuete->getSequence()->getHasAction()){
                $actions = $sequenceActionRepository->getAllActionsBySequence($userQuete->getSequence()->getId());
            }
            $hasConditionalAction = $questService->checkSequenceHaveConditionalAction($actions);
            $response = [
                'title' => $userQuete->getQuete()->getName(),
                'dialogue' => $userQuete->getSequence()->getDialogue()->getContenu() ?? '',
                'actions' => $actions ?? [],
                'sequenceId' => $userQuete->getSequence()->getId(),
                'hasConditionalAction' => $hasConditionalAction,
                'respectSequenceConditions' => true
            ];
        }else{
            $response = ["Erreur" => "vous n'avez pas le niveau nécéssaire pour réaliser l'action"];
        }


        return new Response(json_encode($response));
    }
}
