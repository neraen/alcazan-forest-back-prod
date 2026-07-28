<?php

namespace App\Controller;

use App\DTO\DonjonEditor\DonjonIdDTO;
use App\DTO\DonjonEditor\DonjonSaveDTO;
use App\Exception\DonjonException;
use App\service\DonjonEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints admin du DonjonMaker. Tout le préfixe /api/donjon/editor est réservé
 * ROLE_ADMIN dans security.yaml (lectures comprises), comme le QuestMaker.
 */
#[Route("/api/donjon/editor", name: "api_donjon_editor_")]
class DonjonEditorController extends AbstractController
{
    public function __construct(private readonly DonjonEditorService $editorService){}

    #[Route("/list", name: "list", methods: ["POST"])]
    public function list(): Response
    {
        return new JsonResponse($this->editorService->listDonjons());
    }

    #[Route("/get", name: "get", methods: ["POST"])]
    public function get(#[MapRequestPayload] DonjonIdDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->getDonjonForEditor($dto->donjonId));
    }

    /** Cartes, monstres et types de salle en un seul appel. */
    #[Route("/referentiels", name: "referentiels", methods: ["POST"])]
    public function referentiels(): Response
    {
        return new JsonResponse($this->editorService->getReferentiels());
    }

    /** Config des mécaniques : quels champs afficher pour quel type. */
    #[Route("/config", name: "config", methods: ["POST"])]
    public function config(): Response
    {
        return new JsonResponse($this->editorService->getMecaniqueConfig());
    }

    /** Création (id absent) ou mise à jour complète, transactionnelle, ids stables. */
    #[Route("/save", name: "save", methods: ["POST"])]
    public function save(#[MapRequestPayload] DonjonSaveDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->saveDonjon($dto->toArray()));
    }

    #[Route("/delete", name: "delete", methods: ["POST"])]
    public function delete(#[MapRequestPayload] DonjonIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $this->editorService->deleteDonjon($dto->donjonId);

            return ['deleted' => true];
        });
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (DonjonException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
