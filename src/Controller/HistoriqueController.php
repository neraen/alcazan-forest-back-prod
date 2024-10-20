<?php

namespace App\Controller;

use App\Repository\HistoriqueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class HistoriqueController extends AbstractController{

    public function __construct(){}


    #[Route("/historique/infos", name:"historique_infos")]
    public function getHistoriqueInfos(HistoriqueRepository $historiqueRepository): Response {
        $user = $this->getUser();
        $historiqueInfos = $historiqueRepository->getAllRowsForPlayer($user->getId());

        return new Response(json_encode([
            'rows' => $historiqueInfos ?? []
        ]));
    }

}