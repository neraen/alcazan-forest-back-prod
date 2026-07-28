<?php

namespace App\Controller;

use App\DTO\Interaction\InteractionIdDTO;
use App\DTO\Interaction\InteractionSaveDTO;
use App\Exception\InteractionException;
use App\service\InteractionEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints admin de l'InteractionMaker. Tout le préfixe /api/interaction/editor est
 * réservé ROLE_ADMIN dans security.yaml (lectures comprises) ; /api/interaction/executer
 * reste une route joueur.
 */
#[Route("/api/interaction/editor", name: "api_interaction_editor_")]
class InteractionEditorController extends AbstractController
{
    public function __construct(private readonly InteractionEditorService $editorService){}

    #[Route("/list", name: "list", methods: ["POST"])]
    public function list(): Response
    {
        return new JsonResponse($this->editorService->lister());
    }

    #[Route("/get", name: "get", methods: ["POST"])]
    public function get(#[MapRequestPayload] InteractionIdDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->pourEditeur($dto->interactionId));
    }

    /** Catalogues (métiers, objets, classes, quêtes…) en un seul appel. */
    #[Route("/referentiels", name: "referentiels", methods: ["POST"])]
    public function referentiels(): Response
    {
        return new JsonResponse($this->editorService->referentiels());
    }

    /** Types, portées et conditions : quels champs afficher pour quoi. */
    #[Route("/config", name: "config", methods: ["POST"])]
    public function config(): Response
    {
        return new JsonResponse($this->editorService->config());
    }

    #[Route("/save", name: "save", methods: ["POST"])]
    public function save(#[MapRequestPayload] InteractionSaveDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->sauvegarder($dto->toArray()));
    }

    #[Route("/delete", name: "delete", methods: ["POST"])]
    public function delete(#[MapRequestPayload] InteractionIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $this->editorService->supprimer($dto->interactionId);

            return ['deleted' => true];
        });
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (InteractionException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
