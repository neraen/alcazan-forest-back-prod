<?php

namespace App\Controller;

use App\Repository\BossRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class BossControlleur extends AbstractController{

    public function __construct(){}

    #[Route("/bosses", name:"all_bosses", methods: ["POST"])]
    public function getAllBosses( BossRepository $bossRepository): Response {
        $bosses = $bossRepository->findAll();
        $bossesNormalized = [];

        foreach ($bosses as $boss) {
            $bossesNormalized[] = [
                'id' => $boss->getId(),
                'name' => $boss->getName(),
                'icone' => $boss->getIcone(),
            ];
        }

        return new JsonResponse([
            'bosses' => $bossesNormalized
        ]);
    }
}