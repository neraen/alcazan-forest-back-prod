<?php

namespace App\Controller;

use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\service\MapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class AubergeController extends AbstractController{

    public function __construct(){}

    #[Route("/auberge/entrer", name:"auberge_entrer")]
    public function entrerAuberge(
        MapService              $mapService,
        CarteRepository         $carteRepository,
        CarteCarreauRepository  $carteRepositoryCarreau,
        EntityManagerInterface  $entityManager
    ): Response {
        $user = $this->getUser();
        $initialMap = $carteRepository->find($user->getMap()->getId());
        $auberges = $carteRepository->findBy(['is_auberge' => true]);
        $nearestAuberge= $mapService->getNearestMapInList($initialMap, $auberges);

        $carteRepositoryCarreau->updatePlayerInCase($user);
        $carteRepositoryCarreau->setPlayerOnCaseInAMap($nearestAuberge->getId(), 11, 10, $user->getId());
        $user->setCaseAbscisse(11);
        $user->setCaseOrdonnee(10);
        $user->setMap($nearestAuberge);
        $user->setTimeAuberge(new \DateTime());

        $entityManager->persist($user);
        $entityManager->flush();

        return new Response(json_encode([
            'message' => "Vous entrez dans votre chambre d'auberge"
        ]));
    }
}