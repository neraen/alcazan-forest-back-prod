<?php

namespace App\Controller;

use App\DTO\HotelVente\HotelVenteAchatDTO;
use App\DTO\HotelVente\HotelVenteCatalogueDTO;
use App\DTO\HotelVente\HotelVenteDepotDTO;
use App\DTO\HotelVente\HotelVenteIdDTO;
use App\Exception\HotelVenteIndisponibleException;
use App\service\HotelVenteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API de l'hôtel des ventes. Contrôleur fin : toute la logique vit dans HotelVenteService.
 * Le joueur courant vient de l'authentification, jamais du payload — c'est ce qui empêche
 * d'acheter au nom d'autrui ou de retirer le lot d'un autre.
 *
 * Un lot devenu indisponible (vendu, retiré, expiré, prix changé) répond 409 et non 400 : ce
 * n'est pas une faute du joueur mais un écran périmé, et le front doit se resynchroniser.
 *
 * Pas de règle dans security.yaml : `^/api → IS_AUTHENTICATED_FULLY` couvre déjà ces routes,
 * et l'hôtel n'a pas de back-office admin.
 */
#[Route("/api", name: "api_")]
class HotelVenteController extends AbstractController
{
    public function __construct(
        private readonly HotelVenteService $hotelVenteService
    ) {}

    #[Route("/hotel/catalogue", name: "hotel_catalogue", methods: ["POST"])]
    public function catalogue(#[MapRequestPayload] HotelVenteCatalogueDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->hotelVenteService->catalogue(
            $this->getUser(),
            $dto->type,
            $dto->recherche,
            $dto->tri(),
            $dto->page()
        ));
    }

    #[Route("/hotel/mes-ventes", name: "hotel_mes_ventes", methods: ["POST"])]
    public function mesVentes(): Response
    {
        return $this->handle(fn (): array => $this->hotelVenteService->mesVentes($this->getUser()));
    }

    #[Route("/hotel/vendre", name: "hotel_vendre", methods: ["POST"])]
    public function vendre(#[MapRequestPayload] HotelVenteDepotDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->hotelVenteService->mettreEnVente(
            $this->getUser(),
            $dto->type,
            $dto->itemId,
            $dto->quantite,
            $dto->prix
        ));
    }

    #[Route("/hotel/acheter", name: "hotel_acheter", methods: ["POST"])]
    public function acheter(#[MapRequestPayload] HotelVenteAchatDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->hotelVenteService->acheter(
            $this->getUser(),
            $dto->annonceId,
            $dto->prixAttendu
        ));
    }

    #[Route("/hotel/retirer", name: "hotel_retirer", methods: ["POST"])]
    public function retirer(#[MapRequestPayload] HotelVenteIdDTO $dto): Response
    {
        return $this->handle(fn (): array => $this->hotelVenteService->retirer(
            $this->getUser(),
            $dto->annonceId
        ));
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (HotelVenteIndisponibleException $exception) {
            return new JsonResponse([
                'code' => 'hotel_vente_indisponible',
                'error' => $exception->getMessage(),
                'annonce' => $exception->getAnnonce(),
            ], Response::HTTP_CONFLICT);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
