<?php

namespace App\Controller;

use App\Repository\DonjonGroupeRepository;
use App\Repository\EchangeRepository;
use App\service\MercureJwtFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Délivre au joueur authentifié (JWT Lexik) son token d'ABONNEMENT Mercure, limité à ses
 * topics : `user/{id}` (notifications personnelles, invitations d'échange, lancement de
 * donjon) et, s'il est engagé, `echange/{id}` de sa session active ou
 * `donjon-groupe/{id}` de son groupe de donjon. Le front redemande un token quand une
 * session/un groupe s'ouvre (le topic est alors inclus) ou quand le token expire.
 * JAMAIS de wildcard : chaque topic est listé explicitement.
 */
#[Route("/api", name: "api_")]
class MercureController extends AbstractController
{
    #[Route("/mercure/token", name: "mercure_token", methods: ["POST"])]
    public function token(
        MercureJwtFactory $jwtFactory,
        EchangeRepository $echangeRepository,
        DonjonGroupeRepository $donjonGroupeRepository
    ): Response
    {
        $user = $this->getUser();

        $topics = [sprintf('user/%d', $user->getId())];
        $sessionActive = $echangeRepository->findSessionActive($user);
        if ($sessionActive !== null) {
            $topics[] = sprintf('echange/%d', $sessionActive->getId());
        }

        $groupeDonjon = $donjonGroupeRepository->findGroupeDuJoueur($user);
        if ($groupeDonjon !== null) {
            $topics[] = sprintf('donjon-groupe/%d', $groupeDonjon->getId());
        }

        return new JsonResponse($jwtFactory->creerTokenAbonnement($topics));
    }
}
