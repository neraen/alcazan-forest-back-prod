<?php

namespace App\Controller;

use App\Entity\Pnj;
use App\Repository\PnjRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration des PNJ. L'interaction joueur ↔ PNJ vit dans
 * QuestController (POST /api/pnj/interaction).
 */
#[Route("/api", name:"api_")]
class PnjController extends AbstractController
{
    public function __construct(){}

    #[Route("/pnj/infos", name:"pnj_infos", methods: ["POST"])]
    public function getCasesInfoForSelect(PnjRepository $pnjRepository): Response {
        $pnjInfos = $pnjRepository->findAllAssoc();
        return new JsonResponse($pnjInfos);
    }

    #[Route("/pnj/create", name:"pnj_create", methods: ["POST"])]
    public function createPnj(Request $request, EntityManagerInterface $entityManager, PnjRepository $pnjRepository): Response {
        $data = json_decode($request->getContent(), true);
        $pnj = $data['pnj'];

        if(!empty($pnj["id"])){
            $pnjEntity = $pnjRepository->find($pnj["id"]);
        }else{
            $pnjEntity = new Pnj();
        }


        $pnjEntity->setName($pnj['name']);
        $pnjEntity->setAvatar($pnj['avatar']);
        $pnjEntity->setSkin($pnj['skin']);
        $pnjEntity->setDescription($pnj['description']);
        $pnjEntity->setType($pnj['type']);

        $entityManager->persist($pnjEntity);
        $entityManager->flush();

        return new JsonResponse("ok");
    }
}
