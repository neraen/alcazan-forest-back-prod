<?php

namespace App\Controller;

use App\Entity\UserQuete;
use App\Enum\ConditionalAction;
use App\Event\NextQuestSequenceEvent;
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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/api/action", name:"api_")]
class ActionController extends AbstractController
{
    #[Route("/passer/dialogue", name:"action_passer_dialogue")]
    public function actionPasserDialogue(
        Request $request,
        SequenceRepository $sequenceRepository,
        SequenceActionRepository $sequenceActionRepository,
        UserQueteRepository $userQueteRepository,
        EntityManagerInterface $entityManager
    ): Response {

        $data = json_decode($request->getContent(), true);
        if(isset($data['sequenceId'])){
            $actualSequence = $sequenceRepository->find($data['sequenceId']);
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
            if($nextSequence->getHasAction()){
                $actions = $sequenceActionRepository->getAllActionsBySequence($nextSequence->getId());
            }

            $hasConditionalAction = false;
            foreach ($actions as $action){
                if(in_array($action['actionTypeId'], ConditionalAction::getValues())){
                    $hasConditionalAction = true;
                }
            }

            $questData = [
                'title' => $quete->getName(),
                'dialogue' => $dialogue ?? '',
                'actions' => $actions ?? [],
                'sequenceId' => $nextSequence->getId(),
                'hasConditionalAction' => $hasConditionalAction
            ];

            return new Response(json_encode($questData));
        }else{
            return new Response("Erreur : il n'y a pas de séquence renseignée");
        }
    }

    #[Route("/donner/objet", name:"action_donner_objet")]
    public function actionDonnerObjet(
        Request $request,
        QuestService $questService,
        ActionRepository $actionRepository,
        InventaireObjetRepository $inventaireObjetRepository,
        InventaireRepository $inventaireRepository,
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher
    ): Response {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $action = $actionRepository->find($data['actionId']);
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
            $response = "Success : Vous avez bien donné les objets demandés";
            $eventDispatcher->dispatch(new NextQuestSequenceEvent($user->getId(), $data['sequenceId']));
            $questService->setNextSequence($user->getId(), $data['sequenceId']);
        }else{
            $response = "Erreur : vous n'avez pas les objets nécéssaire pour réaliser l'action";
        }


        return new Response(json_encode($response));
    }

    #[Route("/atteindre/level", name:"action_atteindre_level")]
    public function actionAtteindreLevel(
        Request $request,
        ActionRepository $actionRepository,
        NiveauJoueurRepository $niveauJoueurRepository
    ): Response {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $action = $actionRepository->find($data['actionId']);
        $userLevel = $niveauJoueurRepository->getPlayerLevel($user->getId());

        if($userLevel >= $action->getQuantity()){
            $response = "Success : Vous avez bien le niveau nécéssaire pour réaliser l'action";
        }else{
            $response = "Erreur : vous n'avez pas le niveau nécéssaire pour réaliser l'action";
        }


        return new Response(json_encode($response));
    }
}
