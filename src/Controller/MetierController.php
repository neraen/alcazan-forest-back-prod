<?php

namespace App\Controller;

use App\DTO\Metier\MetierIdDTO;
use App\Exception\MetierException;
use App\service\MetierService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints joueur des métiers. Toute la logique vit dans MetierService, qui ne flushe
 * pas : c'est ici qu'on ouvre la transaction, comme partout ailleurs dans le projet.
 *
 * L'apprentissage se fait en pratique depuis la vue d'un maître de métier
 * (PnjInteractionService, `view: metier`), mais l'endpoint ne l'exige pas : le PNJ est
 * une mise en scène, la règle du jeu est le plafond par famille.
 */
#[Route("/api/metier", name: "api_metier_")]
class MetierController extends AbstractController
{
    public function __construct(
        private readonly MetierService $metierService,
        private readonly EntityManagerInterface $entityManager
    ){}

    /** Progression de métier du joueur (fiche de personnage). */
    #[Route("/progression", name: "progression", methods: ["POST"])]
    public function progression(): Response
    {
        return new JsonResponse($this->metierService->progressionDe($this->getUser()));
    }

    #[Route("/apprendre", name: "apprendre", methods: ["POST"])]
    public function apprendre(#[MapRequestPayload] MetierIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $user = $this->getUser();

            return $this->entityManager->wrapInTransaction(function () use ($dto, $user): array {
                $metier = $this->metierService->trouverMetier($dto->metierId);
                $progression = $this->metierService->apprendre($user, $metier);
                $this->entityManager->flush();

                return [
                    'message' => "Vous voilà {$metier->getNom()}.",
                    'metier' => $progression,
                    'placesRestantes' => $this->metierService->placesRestantes($user),
                ];
            });
        });
    }

    /** Oubli d'un métier : la progression est PERDUE (la confirmation est côté front). */
    #[Route("/oublier", name: "oublier", methods: ["POST"])]
    public function oublier(#[MapRequestPayload] MetierIdDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $user = $this->getUser();

            return $this->entityManager->wrapInTransaction(function () use ($dto, $user): array {
                $metier = $this->metierService->trouverMetier($dto->metierId);
                $this->metierService->oublier($user, $metier);
                $this->entityManager->flush();

                return [
                    'message' => "Vous n'exercez plus le métier de {$metier->getNom()}.",
                    'placesRestantes' => $this->metierService->placesRestantes($user),
                ];
            });
        });
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (MetierException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
