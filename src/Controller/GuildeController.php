<?php

namespace App\Controller;

use App\Repository\GuildeRepository;
use App\Repository\JoueurGuildeRepository;
use App\Repository\NiveauJoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class GuildeController extends AbstractController{

    public function __construct(){}

    #[Route("/guildes/player/check", name:"guildes_player_check", methods: ["POST"])]
    public function checkIfPlayerCanJoinGuilde(GuildeRepository $guildeRepository): Response {
        $user = $this->getUser();

        if($user->getAlignement() === null){
            return new JsonResponse([
                'message' => "Vous devez choisir un alignement avant de rejoindre une guilde"
            ]);
        }

        $guildeRepository->getAllGuildesForPlayer($user->getAlignement()->getId());

        return new JsonResponse([
            'message' => "Vous entrez dans votre chambre d'auberge"
        ]);
    }

    #[Route("/guildes/player", name:"guildes_player", methods: ["POST"])]
    public function getAllGuildesForPlayer(GuildeRepository $guildeRepository): Response {
        $user = $this->getUser();

        if($user->getAlignement() === null){
            return new JsonResponse(['guildes' => []]);
        }

        $guildes = $guildeRepository->getAllGuildesForPlayer($user->getAlignement()->getId());

        return new JsonResponse([
            'guildes' => $guildes
        ]);
    }


    #[Route("/guilde/infos", name:"guilde_infos", methods: ["POST"])]
    public function getGuildeInfos(JoueurGuildeRepository $joueurGuildeRepository, NiveauJoueurRepository $niveauJoueurRepository): Response {
        $user = $this->getUser();
        $guilde = $user->getGuilde();

        $message = "";
        if($guilde){
            $joueurs = $joueurGuildeRepository->getAllPlayerOfAGuilde($guilde->getId());
            foreach($joueurs as &$joueur){
                // Le niveau de CHAQUE membre (l'ancien code renvoyait celui de l'appelant)
                $joueur['niveau'] = $niveauJoueurRepository->getPlayerLevel($joueur['userId']);
            }
            unset($joueur);

            $guildeInfos = [
                'nom' => $guilde->getNom(),
                'description' => $guilde->getDescription(),
                'niveau' => $guilde->getNiveau(),
                'icone' => $guilde->getIcone(),
                'placeMax' => $guilde->getPlaceMax(),
            ];

        }else{
            $message = "Vous n'avez pas de guilde.";
        }

        return new JsonResponse([
            'message' => $message,
            'joueurs' => $joueurs ?? [],
            'infos' => $guildeInfos ?? []
        ]);
    }
}