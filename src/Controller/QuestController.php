<?php

namespace App\Controller;

use App\DTO\Quest\MapActionDTO;
use App\DTO\Quest\PnjInteractionDTO;
use App\DTO\Quest\QuestActionDTO;
use App\DTO\Quest\QuestStartDTO;
use App\Exception\QuestException;
use App\Repository\PnjRepository;
use App\service\PnjInteractionService;
use App\service\QuestProgressionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints joueur du système de quêtes. Toute la logique vit dans
 * QuestProgressionService (machine à états) et PnjInteractionService ;
 * les erreurs métier (QuestException) sortent en 400 avec message FR.
 */
#[Route("/api", name: "api_")]
class QuestController extends AbstractController
{
    public function __construct(
        private readonly QuestProgressionService $questProgressionService,
        private readonly PnjInteractionService $pnjInteractionService,
        private readonly PnjRepository $pnjRepository
    ){}

    /** Ouverture d'un PNJ : lecture pure, la vue dépend du type du PNJ. */
    #[Route("/pnj/interaction", name: "pnj_interaction", methods: ["POST"])]
    public function getPnjInteraction(#[MapRequestPayload] PnjInteractionDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $pnj = $this->pnjRepository->find($dto->pnjId);
            if ($pnj === null) {
                throw new QuestException("PNJ introuvable.");
            }

            return $this->pnjInteractionService->getInteraction($this->getUser(), $pnj);
        });
    }

    /** Démarrage explicite de la quête d'un PNJ (prérequis vérifiés). */
    #[Route("/quest/start", name: "quest_start", methods: ["POST"])]
    public function startQuest(#[MapRequestPayload] QuestStartDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $pnj = $this->pnjRepository->find($dto->pnjId);
            if ($pnj === null) {
                throw new QuestException("PNJ introuvable.");
            }

            return $this->questProgressionService->startQuest($this->getUser(), $pnj);
        });
    }

    /** L'unique endpoint d'action de quête, tous types confondus. */
    #[Route("/quest/action", name: "quest_action", methods: ["POST"])]
    public function questAction(#[MapRequestPayload] QuestActionDTO $dto): Response
    {
        return $this->handle(
            fn (): array => $this->questProgressionService->executeAction(
                $this->getUser(),
                $dto->sequenceId,
                $dto->actionId
            )
        );
    }

    /** Action posée sur une case de la carte (effet scripté). */
    #[Route("/map/action", name: "map_action", methods: ["POST"])]
    public function mapAction(#[MapRequestPayload] MapActionDTO $dto): Response
    {
        return $this->handle(
            fn (): array => $this->questProgressionService->executeMapAction($this->getUser(), $dto->actionId)
        );
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (QuestException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
