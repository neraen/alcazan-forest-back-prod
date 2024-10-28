<?php

namespace App\Controller;

use App\Repository\BossRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class TargetController extends AbstractController
{
    public function __construct(){}

    #[Route("/target/player", name:"get_targeted_player")]
    public function getTargetedPlayer(Request $request, UserRepository $userRepository): Response {
        $data = json_decode($request->getContent(), true);
        $returnTargetInfo = $userRepository->getTargetedPlayer($data['targetId']);

        return new Response(json_encode($returnTargetInfo));
    }

    #[Route("/target/monstre", name:"get_targeted_monstre")]
    public function getTargetedMonstre(Request $request, MonstreCarreauRepository $monstreCarreauRepository): Response{
        $data = json_decode($request->getContent(), true);
        $returnTargetInfo = $monstreCarreauRepository->getTargetedMonstre($data['targetId']);

        return new Response(json_encode($returnTargetInfo));
    }


    #[Route("/target/boss", name:"get_targeted_boss")]
    public function getTargetedBoss(Request $request, BossRepository $bossRepository): Response
    {
        $data = json_decode($request->getContent(), true);
        $returnTargetInfo = $bossRepository->getTargetedBoss($data['targetId']);

        return new Response(json_encode($returnTargetInfo));
    }

}
