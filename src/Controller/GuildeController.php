<?php

namespace App\Controller;

use App\DTO\Guilde\GuildeCibleDTO;
use App\DTO\Guilde\GuildeCreationDTO;
use App\DTO\Guilde\GuildeIdDTO;
use App\Enum\GradeGuilde;
use App\Exception\GuildeException;
use App\service\GuildeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guildes : consultation et transitions. Contrôleur FIN — toute la règle est dans
 * `GuildeService`, unique machine à états.
 *
 * Un refus métier (`GuildeException`) vaut 400 avec un message destiné au JOUEUR : il doit
 * dire ce qui bloque, pas ce qui a planté.
 */
#[Route("/api/guilde", name: "api_guilde_")]
class GuildeController extends AbstractController
{
    public function __construct(private readonly GuildeService $guildeService) {}

    /** L'état vu par le joueur : sa guilde, ses membres, ses candidatures s'il peut les voir. */
    #[Route("/etat", name: "etat", methods: ["POST"])]
    public function etat(): Response
    {
        return new JsonResponse($this->guildeService->etat($this->getUser()));
    }

    /** Les guildes de son alignement — les seules qu'il puisse rejoindre. */
    #[Route("/annuaire", name: "annuaire", methods: ["POST"])]
    public function annuaire(): Response
    {
        return new JsonResponse($this->guildeService->annuaire($this->getUser()));
    }

    #[Route("/creer", name: "creer", methods: ["POST"])]
    public function creer(#[MapRequestPayload] GuildeCreationDTO $dto): Response
    {
        return $this->transition(fn (): array => $this->guildeService->creer(
            $this->getUser(),
            (string) $dto->nom,
            $dto->description
        ));
    }

    #[Route("/candidater", name: "candidater", methods: ["POST"])]
    public function candidater(#[MapRequestPayload] GuildeIdDTO $dto): Response
    {
        return $this->transition(fn (): array => $this->guildeService->candidater($this->getUser(), (int) $dto->guildeId));
    }

    #[Route("/accepter", name: "accepter", methods: ["POST"])]
    public function accepter(#[MapRequestPayload] GuildeCibleDTO $dto): Response
    {
        return $this->transition(fn (): array => $this->guildeService->accepter($this->getUser(), (int) $dto->userId));
    }

    #[Route("/refuser", name: "refuser", methods: ["POST"])]
    public function refuser(#[MapRequestPayload] GuildeCibleDTO $dto): Response
    {
        return $this->transition(fn (): array => $this->guildeService->refuser($this->getUser(), (int) $dto->userId));
    }

    #[Route("/promouvoir", name: "promouvoir", methods: ["POST"])]
    public function promouvoir(#[MapRequestPayload] GuildeCibleDTO $dto): Response
    {
        $grade = $dto->grade === null ? null : GradeGuilde::tryFrom($dto->grade);
        if ($grade === null) {
            return new JsonResponse(['message' => "Ce grade n'existe pas."], Response::HTTP_BAD_REQUEST);
        }

        return $this->transition(fn (): array => $this->guildeService->promouvoir($this->getUser(), (int) $dto->userId, $grade));
    }

    #[Route("/transmettre", name: "transmettre", methods: ["POST"])]
    public function transmettre(#[MapRequestPayload] GuildeCibleDTO $dto): Response
    {
        return $this->transition(fn (): array => $this->guildeService->transmettre($this->getUser(), (int) $dto->userId));
    }

    #[Route("/exclure", name: "exclure", methods: ["POST"])]
    public function exclure(#[MapRequestPayload] GuildeCibleDTO $dto): Response
    {
        return $this->transition(fn (): array => $this->guildeService->exclure($this->getUser(), (int) $dto->userId));
    }

    #[Route("/quitter", name: "quitter", methods: ["POST"])]
    public function quitter(): Response
    {
        return $this->transition(fn (): array => $this->guildeService->quitter($this->getUser()));
    }

    #[Route("/dissoudre", name: "dissoudre", methods: ["POST"])]
    public function dissoudre(): Response
    {
        return $this->transition(fn (): array => $this->guildeService->dissoudre($this->getUser()));
    }

    /**
     * Exécute une transition et renvoie TOUJOURS l'état frais.
     *
     * Le front n'a donc jamais à recharger derrière une action ni à deviner le nouvel état —
     * même patron que `EchangeService`, dont la réponse est toujours le payload normalisé.
     */
    private function transition(callable $action): Response
    {
        try {
            return new JsonResponse($action());
        } catch (GuildeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            // Remontée de SacService : or insuffisant à la fondation, par exemple.
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
