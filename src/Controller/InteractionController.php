<?php

namespace App\Controller;

use App\DTO\Interaction\CaseDTO;
use App\Exception\DonjonException;
use App\Exception\InteractionException;
use App\Exception\QuestException;
use App\service\InteractionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints joueur des cases interactives. Toute la logique vit dans InteractionService ;
 * les erreurs métier sortent en 400 avec un message FR, comme pour les quêtes et donjons.
 */
#[Route("/api", name: "api_")]
class InteractionController extends AbstractController
{
    public function __construct(
        private readonly InteractionService $interactionService
    ){}

    #[Route("/interaction/executer", name: "interaction_executer", methods: ["POST"])]
    public function executer(#[MapRequestPayload] CaseDTO $dto): Response
    {
        try {
            return new JsonResponse(
                $this->interactionService->executer($this->getUser(), $dto->carteCarreauId, $dto->mode)
            );
        } catch (InteractionException|QuestException|DonjonException $exception) {
            // Un effet scripté peut refuser pour ses propres raisons (levier hors donjon,
            // coffre vide) : son message est déjà destiné au joueur.
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
