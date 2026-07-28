<?php

namespace App\Controller;

use App\DTO\Echange\EchangeCreateDTO;
use App\DTO\Echange\EchangeIdDTO;
use App\DTO\Echange\EchangeItemAddDTO;
use App\DTO\Echange\EchangeItemRemoveDTO;
use App\DTO\Echange\EchangeOrDTO;
use App\DTO\Echange\EchangeVersionDTO;
use App\Exception\EchangeConflitException;
use App\service\EchangeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API du système d'échange joueur-à-joueur. Contrôleur fin : toute la logique vit dans
 * EchangeService. Le joueur courant vient de l'authentification, jamais du payload.
 * Un conflit de version (l'autre joueur a agi entre-temps) répond 409 avec l'état frais.
 */
#[Route("/api", name: "api_")]
class EchangeController extends AbstractController
{
    public function __construct(
        private readonly EchangeService $echangeService
    ) {}

    #[Route("/echange/create", name: "echange_create", methods: ["POST"])]
    public function create(#[MapRequestPayload] EchangeCreateDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->creer($this->getUser(), $dto->cibleId),
        ]);
    }

    #[Route("/echange/accept", name: "echange_accept", methods: ["POST"])]
    public function accept(#[MapRequestPayload] EchangeIdDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->accepter($this->getUser(), $dto->echangeId),
        ]);
    }

    #[Route("/echange/decline", name: "echange_decline", methods: ["POST"])]
    public function decline(#[MapRequestPayload] EchangeIdDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->refuser($this->getUser(), $dto->echangeId),
        ]);
    }

    /** Reprise après reconnexion (et secours sans Mercure) : session active + invitations. */
    #[Route("/echange/current", name: "echange_current", methods: ["POST"])]
    public function current(): Response
    {
        return $this->handle(fn (): array => $this->echangeService->getEtatCourant($this->getUser()));
    }

    #[Route("/echange/item/add", name: "echange_item_add", methods: ["POST"])]
    public function addItem(#[MapRequestPayload] EchangeItemAddDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->proposerItem(
                $this->getUser(),
                $dto->type,
                $dto->itemId,
                $dto->quantite,
                $dto->expectedVersion
            ),
        ]);
    }

    #[Route("/echange/item/remove", name: "echange_item_remove", methods: ["POST"])]
    public function removeItem(#[MapRequestPayload] EchangeItemRemoveDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->retirerItem($this->getUser(), $dto->ligneId, $dto->expectedVersion),
        ]);
    }

    #[Route("/echange/or", name: "echange_or", methods: ["POST"])]
    public function updateOr(#[MapRequestPayload] EchangeOrDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->modifierOr($this->getUser(), $dto->montant, $dto->expectedVersion),
        ]);
    }

    #[Route("/echange/confirm", name: "echange_confirm", methods: ["POST"])]
    public function confirm(#[MapRequestPayload] EchangeVersionDTO $dto): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->confirmer($this->getUser(), $dto->expectedVersion),
        ]);
    }

    #[Route("/echange/cancel", name: "echange_cancel", methods: ["POST"])]
    public function cancel(): Response
    {
        return $this->handle(fn (): array => [
            'echange' => $this->echangeService->annuler($this->getUser()),
        ]);
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (EchangeConflitException $exception) {
            return new JsonResponse([
                'code' => 'echange_conflit',
                'error' => $exception->getMessage(),
                'echange' => $exception->getEtat(),
            ], Response::HTTP_CONFLICT);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
