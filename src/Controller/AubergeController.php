<?php

namespace App\Controller;

use App\service\AubergeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class AubergeController extends AbstractController{

    public function __construct(){}

    #[Route("/auberge/entrer", name:"auberge_entrer", methods: ["POST"])]
    public function entrerAuberge(AubergeService $aubergeService): Response {
        return new JsonResponse([
            'message' => $aubergeService->entrer($this->getUser())
        ]);
    }
}
