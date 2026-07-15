<?php

namespace App\Controller;

use App\Repository\ConsommableRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route("/api", name:"api_")]
class ConsommableControlleur extends AbstractController {

    public function __construct(){}

    #[Route("/consommables", name:"all_consommables", methods: ["POST"])]
    public function getAllConsommables(ConsommableRepository $consommableRepository): Response {
        $consommables = $consommableRepository->findAll();
        $consommablesNormalized = [];

        foreach ($consommables as $consommable) {
            $consommablesNormalized[] = [
                'id' => $consommable->getId(),
                'name' => $consommable->getNom(),
                'icone' => $consommable->getIcone(),
            ];
        }

        return new JsonResponse([
            'consommables' => $consommablesNormalized
        ]);
    }

}