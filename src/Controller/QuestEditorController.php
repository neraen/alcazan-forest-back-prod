<?php

namespace App\Controller;

use App\DTO\Quest\QuestIdDTO;
use App\DTO\Quest\QuestSaveDTO;
use App\Exception\QuestException;
use App\service\QuestEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints admin du QuestMaker. Tout le préfixe /api/quest/editor est
 * réservé ROLE_ADMIN dans security.yaml (lectures comprises).
 */
#[Route("/api/quest/editor", name: "api_quest_editor_")]
class QuestEditorController extends AbstractController
{
    public function __construct(private readonly QuestEditorService $questEditorService){}

    #[Route("/list", name: "list", methods: ["POST"])]
    public function list(): Response
    {
        return new JsonResponse($this->questEditorService->listQuests());
    }

    #[Route("/get", name: "get", methods: ["POST"])]
    public function get(#[MapRequestPayload] QuestIdDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->questEditorService->getQuestForEditor($dto->questId));
    }

    /** Tous les référentiels (objets, PNJ, boss…) en un seul appel. */
    #[Route("/referentiels", name: "referentiels", methods: ["POST"])]
    public function referentiels(): Response
    {
        return new JsonResponse($this->questEditorService->getReferentiels());
    }

    /** Config des types d'action : quels champs afficher pour quel type. */
    #[Route("/config", name: "config", methods: ["POST"])]
    public function config(): Response
    {
        return new JsonResponse($this->questEditorService->getActionTypeConfig());
    }

    /** Création (id absent) ou mise à jour complète, transactionnelle, ids stables. */
    #[Route("/save", name: "save", methods: ["POST"])]
    public function save(#[MapRequestPayload] QuestSaveDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->questEditorService->saveQuest($dto->toArray()));
    }

    #[Route("/delete", name: "delete", methods: ["POST"])]
    public function delete(#[MapRequestPayload] QuestIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $this->questEditorService->deleteQuest($dto->questId);

            return ['deleted' => true];
        });
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
