<?php

namespace App\Controller;

use App\Entity\Monstre;
use App\Repository\MonstreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class MonstreController extends AbstractController
{
    public function __construct( ){}

    #[Route("/monstres", name:"monstre_info", methods: ["POST"])]
    public function getMonstresInfoForSelect(MonstreRepository $monstreRepository): Response {
        $pnjInfos = $monstreRepository->findAllAssoc();
        return new JsonResponse($pnjInfos);
    }

    #[Route("/monstre/create", name:"monstre_create", methods: ["POST"])]
    public function createMonster(Request $request, EntityManagerInterface $entityManager): Response {
        $data = json_decode($request->getContent(), true);
        $monstre = $data['monstre'];
        $monstreEntity = new Monstre();

        $monstreEntity->setName($monstre['name']);
        $monstreEntity->setMaxLife($monstre['maxLife']);
        $monstreEntity->setSkin($monstre['skin']);
        $monstreEntity->setTempsRepop($monstre['tempsRepop']);
        $monstreEntity->setPuissance($monstre['puissance']);

        $entityManager->persist($monstreEntity);
        $entityManager->flush();

        return new JsonResponse("ok");
    }

}
