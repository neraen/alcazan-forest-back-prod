<?php

namespace App\Controller;

use App\Repository\ObjetRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class ObjectControlleur extends AbstractController{

    public function __construct(){}


    #[Route("/objets", name:"all_objets")]
    public function getAllObjets(ObjetRepository $objetRepository): Response {
        $objets = $objetRepository->findAll();
        $objetsNormalized = [];

        foreach ($objets as $objet) {
            $objetsNormalized[] = [
                'id' => $objet->getId(),
                'name' => $objet->getName(),
                'image' => $objet->getImage(),
            ];
        }

        return new Response(json_encode([
            'objets' => $objetsNormalized
        ]));
    }
}