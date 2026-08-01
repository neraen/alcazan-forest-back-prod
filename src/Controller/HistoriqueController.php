<?php

namespace App\Controller;

use App\service\HistoriqueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class HistoriqueController extends AbstractController{

    public function __construct(){}


    /**
     * Le journal du joueur : événements typés + lignes héritées, en une seule liste.
     *
     * La réponse porte désormais `categories` en plus de `rows` — l'écran n'en connaît
     * aucune en dur, et « Mes actions / Subis » cesse d'être la seule classification
     * honnête possible.
     */
    #[Route("/historique/infos", name:"historique_infos", methods: ["POST"])]
    public function getHistoriqueInfos(HistoriqueService $historiqueService): Response {
        return new JsonResponse($historiqueService->pourJoueur($this->getUser()));
    }

}