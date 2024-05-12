<?php

namespace App\Controller;

use App\Repository\GuildeRepository;
use App\Repository\JoueurGuildeRepository;
use App\Repository\NiveauJoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class GuildeController extends AbstractController{

    public function __construct(){}

    #[Route("/guildes/player/check", name:"guildes_player_check")]
    public function checkIfPlayerCanJoinGuilde(GuildeRepository $guildeRepository): Response {
        $user = $this->getUser();

        $guildeRepository->getAllGuildesForPlayer($user->getAlignement()->getId());

        return new Response(json_encode([
            'message' => "Vous entrez dans votre chambre d'auberge"
        ]));
    }

    #[Route("/guildes/player", name:"guildes_player")]
    public function getAllGuildesForPlayer(GuildeRepository $guildeRepository): Response {
        $user = $this->getUser();

        $guildes = $guildeRepository->getAllGuildesForPlayer($user->getAlignement()->getId());

        return new Response(json_encode([
            'guildes' => $guildes
        ]));
    }


    #[Route("/guilde/infos", name:"guilde_infos")]
    public function getGuildeInfos(JoueurGuildeRepository $joueurGuildeRepository, NiveauJoueurRepository $niveauJoueurRepository): Response {
        $user = $this->getUser();
        $guilde = $user->getGuilde();

        $message = "";
        if($guilde){
            $joueurs = $joueurGuildeRepository->getAllPlayerOfAGuilde($guilde->getId());
            foreach($joueurs as &$joueur){
                $joueur['niveau'] = $niveauJoueurRepository->getPlayerLevel($user->getId());
            }
            $guildeInfos = [];

        }else{
            $message = "Vous n'avez pas de guilde.";
        }

        return new Response(json_encode([
            'message' => $message,
            'joueurs' => $joueurs ?? [],
            'infos' => $guildeInfos ?? []
        ]));
    }
}