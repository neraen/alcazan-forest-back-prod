<?php

namespace App\Controller;

use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\UserEquipementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class ProfilControlleur extends AbstractController
{
    public function __construct(){}


    #[Route("/profil/joueur/equipement", name:"profil_joueur_equipement")]
    function getEquipementEquipeJoueurForProfil(
        Request                             $request,
        UserEquipementRepository            $userEquipementRepository,
        EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository
    ): Response {
        $data = json_decode($request->getContent(), true);
        $userId = $data['userId'];

        $equipements = $userEquipementRepository->getPlayerEquipementEquipe($userId);

        foreach ($equipements as &$equipement){
            $caracterisitques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
            $equipement['caracteristiques'] = $caracterisitques;
        }

        $json = json_encode($equipements);

        return new Response($json);
    }
}