<?php

namespace App\Controller;

use App\Repository\BossRepository;
use App\Repository\DonjonInstanceMonstreRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\UserRepository;
use App\service\DonjonInstanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class TargetController extends AbstractController
{
    public function __construct(){}

    #[Route("/target/player", name:"get_targeted_player", methods: ["POST"])]
    public function getTargetedPlayer(Request $request, UserRepository $userRepository): Response {
        $data = json_decode($request->getContent(), true);
        $returnTargetInfo = $userRepository->getTargetedPlayer($data['targetId']);

        return new JsonResponse($returnTargetInfo);
    }

    #[Route("/target/monstre", name:"get_targeted_monstre", methods: ["POST"])]
    public function getTargetedMonstre(Request $request, MonstreCarreauRepository $monstreCarreauRepository): Response{
        $data = json_decode($request->getContent(), true);
        $returnTargetInfo = $monstreCarreauRepository->getTargetedMonstre($data['targetId']);

        return new JsonResponse($returnTargetInfo);
    }


    /**
     * Cible d'un monstre d'INSTANCE (population d'une salle de donjon, renfort de boss).
     *
     * Renvoie EXACTEMENT les clés de `/target/monstre` : la carte de cible est mutualisée,
     * un monstre de donjon se présente au joueur comme n'importe quel autre monstre. Le
     * renfort mort est renvoyé aussi (vie 0) — le front cible puis décible sur la mort,
     * et une 404 au dernier coup ferait clignoter une erreur pour rien.
     */
    #[Route("/target/renfort", name:"get_targeted_renfort", methods: ["POST"])]
    public function getTargetedRenfort(
        Request $request,
        DonjonInstanceMonstreRepository $renfortRepository,
        DonjonInstanceService $instanceService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $renfort = $renfortRepository->find((int)$data['targetId']);
        $instance = $instanceService->instanceCourante($this->getUser());

        // Une cible d'instance n'existe QUE pour son expédition : on ne renseigne pas le
        // joueur sur le contenu de celle des autres.
        if ($renfort === null || $instance === null || $renfort->getInstance()?->getId() !== $instance->getId()) {
            return new JsonResponse(['error' => "Cible introuvable."], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'quantiteMonstre' => 1,
            'monstreLife' => $renfort->getCurrentLife(),
            'nomMonstre' => $renfort->getMonstre()->getName(),
            'imageMonstre' => $renfort->getMonstre()->getSkin(),
            'monstreLifeMax' => $renfort->getMonstre()->getMaxLife(),
        ]);
    }


    #[Route("/target/boss", name:"get_targeted_boss", methods: ["POST"])]
    public function getTargetedBoss(Request $request, BossRepository $bossRepository): Response
    {
        $data = json_decode($request->getContent(), true);
        $returnTargetInfo = $bossRepository->getTargetedBoss($data['targetId']);

        return new JsonResponse($returnTargetInfo);
    }

}
