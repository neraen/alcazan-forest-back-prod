<?php

namespace App\Controller;

use App\DTO\Craft\CraftCommandeDTO;
use App\DTO\Craft\CraftLancerDTO;
use App\Exception\CraftException;
use App\service\CraftService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints joueur de l'atelier. Toute la logique vit dans CraftService, qui ouvre ses
 * propres transactions (c'est une machine à états, comme InteractionService).
 */
#[Route("/api/craft", name: "api_craft_")]
class CraftController extends AbstractController
{
    public function __construct(private readonly CraftService $craftService){}

    /** Recettes accessibles + file d'attente + modes, en un seul appel. */
    #[Route("/atelier", name: "atelier", methods: ["POST"])]
    public function atelier(): Response
    {
        return $this->handle(fn (): array => $this->craftService->atelier($this->getUser()));
    }

    #[Route("/commandes", name: "commandes", methods: ["POST"])]
    public function commandes(): Response
    {
        return $this->handle(fn (): array => ['commandes' => $this->craftService->commandes($this->getUser())]);
    }

    #[Route("/lancer", name: "lancer", methods: ["POST"])]
    public function lancer(#[MapRequestPayload] CraftLancerDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->craftService->lancer($this->getUser(), $dto->recetteId, $dto->mode));
    }

    #[Route("/retirer", name: "retirer", methods: ["POST"])]
    public function retirer(#[MapRequestPayload] CraftCommandeDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->craftService->retirer($this->getUser(), $dto->commandeId));
    }

    #[Route("/annuler", name: "annuler", methods: ["POST"])]
    public function annuler(#[MapRequestPayload] CraftCommandeDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->craftService->annuler($this->getUser(), $dto->commandeId));
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
