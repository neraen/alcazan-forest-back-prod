<?php

namespace App\Controller;

use App\Entity\Pnj;
use App\Entity\UserQuete;
use App\Repository\GuildeRepository;
use App\Repository\JoueurGuildeRepository;
use App\Repository\PnjRepository;
use App\Repository\SequenceActionRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserQueteRepository;
use App\service\PnjService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class PnjController extends AbstractController
{
    public function __construct(){}

    #[Route("/pnj/infos", name:"pnj_infos")]
    public function getCasesInfoForSelect(PnjRepository $pnjRepository): Response {
        $pnjInfos = $pnjRepository->findAllAssoc();
        return new Response(json_encode($pnjInfos));
    }

    #[Route("/pnj/create", name:"pnj_create")]
    public function createPnj(Request $request, EntityManagerInterface $entityManager): Response {
        $data = json_decode($request->getContent(), true);
        $pnj = $data['pnj'];
        $pnjEntity = new Pnj();

        $pnjEntity->setName($pnj['name']);
        $pnjEntity->setAvatar($pnj['avatar']);
        $pnjEntity->setSkin($pnj['skin']);
        $pnjEntity->setDescription($pnj['description']);
        $pnjEntity->setType($pnj['type']);

        $entityManager->persist($pnjEntity);
        $entityManager->flush();

        return new Response(json_encode("ok"));
    }

    #[Route("/pnj", name:"pnj_info")]
    public function getPnjInfoAndAction(
        Request                 $request,
        PnjService              $pnjService,
        PnjRepository           $pnjRepository,
        SequenceRepository      $sequenceRepository,
        UserQueteRepository     $userQueteRepository,
        EntityManagerInterface  $entityManager,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $idPnj = $data['pnjId'];
        $pnjEntity = $pnjRepository->find($idPnj);

        $pnjInfo = [];

        switch ($pnjEntity->getType()){
            case "shop":
                $pnjInfo = $pnjService->getPnjShop($pnjEntity);
                $pnjInfo['typePnj'] = "shop";
                break;
            case "quest":
                $pnjInfo['typePnj'] = "quest";
                $pnjInfo['title'] = $pnjEntity->getQuete()->getName();
                $userBeginQuest = $userQueteRepository->findOneBy(['user' => $this->getUser()->getId(), 'quete' => $pnjEntity->getQuete()->getId()]);
                if(!$userBeginQuest){
                    $firstSequenceOfQuest = $sequenceRepository->findOneBy(['quete' => $pnjEntity->getQuete()->getId(), 'pnj' => $pnjEntity->getId(),'position' => 1]);
                    $userQueteEntity = new UserQuete();
                    $userQueteEntity->setUser($this->getUser());
                    $userQueteEntity->setQuete($pnjEntity->getQuete());
                    $userQueteEntity->setSequence($firstSequenceOfQuest);
                    $userQueteEntity->setIsDone(false);

                    $entityManager->persist($userQueteEntity);
                    $entityManager->flush();
                }
                break;
                //$pnjInfo = $pnjService->getPnjShop($pnjEntity);
            case "action":
                $pnjInfo['typePnj'] = "action";
                $pnjInfo['title'] = $pnjEntity->getName();
                //$pnjInfo = $pnjService->getPnjShop($pnjEntity);
                break;
            case "guilde":
                $pnjInfo['typePnj'] = "guilde";
                $pnjInfo['title'] = $pnjEntity->getName();
                break;
        }

        return new Response(json_encode($pnjInfo));
    }

    #[Route("/pnj/action", name:"pnj_action")]
    public function getPnjAction(
        Request                     $request,
        PnjRepository               $pnjRepository,
        SequenceRepository          $sequenceRepository,
        SequenceActionRepository    $sequenceActionRepository
    ): Response {
        $data = json_decode($request->getContent(), true);
        $idPnj = $data['pnjId'];
        $pnjEntity = $pnjRepository->find($idPnj);
        $sequence = $sequenceRepository->findOneBy(['pnj' => $pnjEntity->getId()]);

        if($sequence->getHasAction()){
            $actions = $sequenceActionRepository->getAllActionsBySequence($sequence->getId());
        }

        return new  Response(json_encode([
            'dialogue' => $sequence->getDialogue()->getContenu(),
            'actions' => $actions ?? []
        ]));
    }

    #[Route("/pnj/guildes", name:"pnj_guilde")]
    public function getPnjGuilde(
        Request                 $request,
        GuildeRepository        $guildeRepository,
        JoueurGuildeRepository  $joueurGuildeRepository,
        PnjRepository           $pnjRepository,
        SequenceRepository      $sequenceRepository
    ): Response {
        $data = json_decode($request->getContent(), true);
        $idPnj = $data['pnjId'];
        $pnjEntity = $pnjRepository->find($idPnj);
        $sequence = $sequenceRepository->findOneBy(['pnj' => $pnjEntity->getId()]);
        $user = $this->getUser();

        $playerHaveGuilde = $joueurGuildeRepository->findOneBy(['user' => $user->getId()]);
        dump($playerHaveGuilde);
        if(!is_null($user->getAlignement())){
            if(is_null($playerHaveGuilde)){
                $guildes = $guildeRepository->getAllGuildesForPlayer($user->getAlignement()->getId());
            }else{
                $message = "Vous faites déjà parti d'une guilde .";
            }
        }else{
            $message = "Vous devez appartenir à un Alignement, pour pouvoir rejoindre une guilde";
        }

        return new  Response(json_encode([
            'dialogue' => isset($message) ? $message : $sequence->getDialogue()->getContenu(),
            'guildes' => $guildes ?? []
        ]));
    }

//    /**
//     #[Route("/pnj/alignement", name:"pnj_alignement")]
//     */
//    public function getPnjAlignement(){
//
//    }


}
