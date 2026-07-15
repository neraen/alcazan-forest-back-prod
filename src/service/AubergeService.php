<?php

namespace App\service;

use App\Entity\User;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use Doctrine\ORM\EntityManagerInterface;

class AubergeService
{
    /** Case fixe de la chambre dans toutes les auberges. */
    private const CHAMBRE_ABSCISSE = 11;
    private const CHAMBRE_ORDONNEE = 10;

    public function __construct(
        private readonly MapService $mapService,
        private readonly CarteRepository $carteRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly EntityManagerInterface $entityManager
    ){}

    /** Téléporte le joueur dans l'auberge la plus proche et horodate son passage. */
    public function entrer(User $user): string
    {
        $initialMap = $user->getMap();
        $auberges = $this->carteRepository->findBy(['is_auberge' => true]);
        $nearestAuberge = $this->mapService->getNearestMapInList($initialMap, $auberges);

        $this->carteCarreauRepository->updatePlayerInCase($user);
        $this->carteCarreauRepository->setPlayerOnCaseInAMap(
            $nearestAuberge->getId(),
            self::CHAMBRE_ABSCISSE,
            self::CHAMBRE_ORDONNEE,
            $user->getId()
        );
        $user->setCaseAbscisse(self::CHAMBRE_ABSCISSE);
        $user->setCaseOrdonnee(self::CHAMBRE_ORDONNEE);
        $user->setMap($nearestAuberge);
        $user->setTimeAuberge(new \DateTime());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return "Vous entrez dans votre chambre d'auberge";
    }
}
