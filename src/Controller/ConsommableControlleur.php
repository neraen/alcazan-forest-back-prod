<?php

namespace App\Controller;

use App\Repository\ConsommableRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/api", name:"api_")]
class ConsommableControlleur extends AbstractController{

    public function __construct(){}

    #[Route("/consommables", name:"all_consommables")]
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

        return new Response(json_encode([
            'consommables' => $consommablesNormalized
        ]));
    }

}