<?php

namespace App\Controller;

use App\Repository\SequenceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class SequenceControlleur extends AbstractController{

    public function __construct(){}

    #[Route("/sequences", name:"sequences")]
    public function getAllSequences(SequenceRepository $sequenceRepository): Response {
        $sequences = $sequenceRepository->findAll();
        $sequencesNormalized = [];

        foreach ($sequences as $sequence) {
            $sequencesNormalized[] = [
                'id' => $sequence->getId(),
                'name' => $sequence->getName()
            ];
        }

        return new Response(json_encode([
            'sequences' => $sequencesNormalized
        ]));
    }
}