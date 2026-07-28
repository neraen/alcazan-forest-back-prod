<?php

namespace App\Controller;

use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\UserEquipementRepository;
use App\service\EquipementEquipeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class InventaireController extends AbstractController
{
    public function __construct(){}

    #[Route("/inventaire", name:"inventaire", methods: ["POST"])]
    public function getPlayerInventaire(
        InventaireRepository                $inventaireRepository,
        InventaireEquipementRepository      $inventaireEquipementRepository,
        InventaireObjetRepository           $inventaireObjetRepository,
        InventaireConsommableRepository     $inventaireConsommableRepository,
        EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository
    ): Response {

        $userId = $this->getUser()->getId();
        $inventaire = $inventaireRepository->findOneBy(['user' => $userId]);
        $hasEquipement = !empty($inventaireEquipementRepository->findBy(["inventaire" => $inventaire]));
        $equipements = $hasEquipement ? $inventaireRepository->getPlayerEquipement($userId) : [];

        $hasObjet = !empty($inventaireObjetRepository->findBy(['inventaire' => $inventaire]));
        $objets = $hasObjet ? $inventaireRepository->getPlayerObjet($userId) : [];

        $hasConsommable = !empty($inventaireConsommableRepository->findBy(['inventaire' => $inventaire]));
        $consommables = $hasConsommable ? $inventaireRepository->getPlayerConsommable($userId) : [];

        foreach ($equipements as &$equipement){
            $caracterisitques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
            $equipement['caracteristiques'] = $caracterisitques;
        }

        $data = ['equipements' => $equipements, 'objets' => $objets, 'consommables' => $consommables];
        return new JsonResponse($data);
    }


    #[Route("/inventaire/equipement/equipe", name:"inventaire_equipement_equipe", methods: ["POST"])]
    public function getPlayerEquipementEquipe(
        UserEquipementRepository            $userEquipementRepository,
        EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository
    ): Response {

        $userId = $this->getUser()->getId();
        $equipements = $userEquipementRepository->getPlayerEquipementEquipe($userId);

        foreach ($equipements as &$equipement){
            $caracterisitques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
            $equipement['caracteristiques'] = $caracterisitques;
        }

        return new JsonResponse($equipements);
    }


    #[Route("/inventaire/equipement/unwear", name:"inventaire_equipement_unwear", methods: ["POST"])]
    public function unwearEquipement(
        Request                  $request,
        EquipementEquipeService  $equipementEquipeService
    ): Response {
        $data = json_decode($request->getContent(), true);

        try {
            $equipementEquipeService->unwear($this->getUser(), (int) ($data['idEquipement'] ?? 0));
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([]);
    }


    #[Route("/inventaire/equipement/wear", name:"inventaire_equipement_wear", methods: ["POST"])]
    public function wearEquipement(
        Request                  $request,
        EquipementEquipeService  $equipementEquipeService
    ): Response {
        $data = json_decode($request->getContent(), true);

        try {
            $equipementEquipeService->wear($this->getUser(), (int) ($data['idEquipement'] ?? 0));
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([]);
    }

}