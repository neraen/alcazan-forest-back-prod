<?php

namespace App\Controller;

use App\Config\ClassementConfig;
use App\DTO\Classement\ClassementCategorieDTO;
use App\Enum\CategorieClassement;
use App\service\ClassementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Classements PUBLICS : accessibles à tout joueur authentifié (règle `^/api` de
 * `security.yaml`), et non réservés à l'administration — c'est le sens même d'un classement.
 *
 * Ils n'exposent que le pseudo, la classe, le niveau et la valeur classée : rien qu'un joueur
 * ne verrait déjà sur la fiche publique d'un autre.
 */
#[Route("/api/classement", name: "api_classement_")]
class ClassementController extends AbstractController
{
    public function __construct(private readonly ClassementService $classementService) {}

    /**
     * Le podium d'une catégorie, plus le référentiel des catégories.
     *
     * Les catégories voyagent avec la liste plutôt que dans un endpoint séparé : l'écran a
     * besoin des deux au premier rendu, et les séparer coûterait un aller-retour pour rien.
     */
    #[Route("/liste", name: "liste", methods: ["POST"])]
    public function liste(#[MapRequestPayload] ClassementCategorieDTO $dto): Response
    {
        $categorie = $dto->resoudre();
        if ($categorie === null) {
            return new JsonResponse(
                ['message' => "Ce classement n'existe pas."],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse([
            'categories' => $this->classementService->categories(),
            'categorie' => $categorie->value,
            'classement' => $this->classementService->top($categorie),
            'taille' => ClassementConfig::TAILLE_TOP,
        ]);
    }

    /**
     * Le rang du joueur courant dans TOUTES les catégories.
     *
     * Toutes d'un coup, parce que la fiche de personnage les affiche ensemble et que le coût
     * est celui de quelques `COUNT` sur index. `rang` vaut null pour un compte exclu des
     * classements — lui en afficher un serait mentir, puisqu'il ne figure dans aucune liste.
     */
    #[Route("/moi", name: "moi", methods: ["POST"])]
    public function moi(): Response
    {
        $user = $this->getUser();

        $rangs = [];
        foreach (CategorieClassement::cases() as $categorie) {
            $rang = $this->classementService->rangDe($user, $categorie);
            $rangs[] = [
                'categorie' => $categorie->value,
                'label' => $categorie->label(),
                'format' => $categorie->format(),
                'rang' => $rang['rang'] ?? null,
                'valeur' => $rang['valeur'] ?? null,
            ];
        }

        return new JsonResponse([
            'rangs' => $rangs,
            'horsClassement' => $user->isHorsClassement(),
        ]);
    }
}
