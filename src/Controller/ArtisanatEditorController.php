<?php

namespace App\Controller;

use App\DTO\Artisanat\ArtisanatIdDTO;
use App\DTO\Artisanat\MetierSaveDTO;
use App\DTO\Artisanat\RecetteSaveDTO;
use App\DTO\Artisanat\RessourceSaveDTO;
use App\Exception\CraftException;
use App\service\ArtisanatEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints admin de l'ArtisanatMaker. Tout le préfixe /api/artisanat/editor est réservé
 * ROLE_ADMIN dans security.yaml (lectures comprises) ; /api/craft/* reste joueur.
 */
#[Route("/api/artisanat/editor", name: "api_artisanat_editor_")]
class ArtisanatEditorController extends AbstractController
{
    public function __construct(private readonly ArtisanatEditorService $editorService){}

    #[Route("/list", name: "list", methods: ["POST"])]
    public function list(): Response
    {
        return new JsonResponse($this->editorService->lister());
    }

    /** Catalogues (métiers, objets, équipements, consommables, PNJ maîtres). */
    #[Route("/referentiels", name: "referentiels", methods: ["POST"])]
    public function referentiels(): Response
    {
        return new JsonResponse($this->editorService->referentiels());
    }

    /** Familles, plafonds et modes de fabrication : le front ne connaît rien en dur. */
    #[Route("/config", name: "config", methods: ["POST"])]
    public function config(): Response
    {
        return new JsonResponse($this->editorService->config());
    }

    #[Route("/metier/get", name: "metier_get", methods: ["POST"])]
    public function metierGet(#[MapRequestPayload] ArtisanatIdDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->metierPourEditeur($dto->id));
    }

    #[Route("/metier/save", name: "metier_save", methods: ["POST"])]
    public function metierSave(#[MapRequestPayload] MetierSaveDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->sauvegarderMetier($dto->toArray()));
    }

    #[Route("/metier/delete", name: "metier_delete", methods: ["POST"])]
    public function metierDelete(#[MapRequestPayload] ArtisanatIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $this->editorService->supprimerMetier($dto->id);

            return ['deleted' => true];
        });
    }

    #[Route("/ressource/save", name: "ressource_save", methods: ["POST"])]
    public function ressourceSave(#[MapRequestPayload] RessourceSaveDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->sauvegarderRessource($dto->toArray()));
    }

    #[Route("/recette/get", name: "recette_get", methods: ["POST"])]
    public function recetteGet(#[MapRequestPayload] ArtisanatIdDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->recettePourEditeur($dto->id));
    }

    #[Route("/recette/save", name: "recette_save", methods: ["POST"])]
    public function recetteSave(#[MapRequestPayload] RecetteSaveDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->editorService->sauvegarderRecette($dto->toArray()));
    }

    #[Route("/recette/delete", name: "recette_delete", methods: ["POST"])]
    public function recetteDelete(#[MapRequestPayload] ArtisanatIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $this->editorService->supprimerRecette($dto->id);

            return ['deleted' => true];
        });
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (CraftException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
