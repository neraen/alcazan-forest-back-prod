<?php

namespace App\Controller;

use App\DTO\Journal\JoueurFicheDTO;
use App\DTO\Journal\JournalFiltreDTO;
use App\Repository\EvenementJeuRepository;
use App\Repository\UserRepository;
use App\service\JournalNormalizer;
use App\service\TableauDeBordService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints d'OBSERVATION de l'administration. Aucune écriture, aucune modération.
 *
 * Le préfixe `/api/admin/` est déjà réservé à ROLE_ADMIN dans `security.yaml`, et cette
 * règle est déjà placée avant le `^/api` fourre-tout : ce contrôleur n'a donc demandé
 * aucune modification de la configuration de sécurité, et ne peut pas reproduire la classe
 * de bug « règle ajoutée après `^/api` ». Comme partout dans ce projet, la protection vit
 * dans `security.yaml` et jamais dans un attribut `#[IsGranted]`.
 *
 * Le préfixe `/editor` a été volontairement écarté : il désigne les *makers*, qui éditent
 * du contenu. Un tableau de bord n'édite rien.
 */
#[Route("/api/admin/stats", name: "api_admin_stats_")]
class AdminStatsController extends AbstractController
{
    public function __construct(
        private readonly EvenementJeuRepository $evenementRepository,
        private readonly JournalNormalizer $normalizer,
        private readonly UserRepository $userRepository,
    ) {}

    /** Une page du journal, filtrée. */
    #[Route("/journal", name: "journal", methods: ["POST"])]
    public function journal(#[MapRequestPayload] JournalFiltreDTO $filtre): Response
    {
        $resultat = $this->evenementRepository->rechercher(
            $filtre->userId,
            $filtre->types(),
            $filtre->depuis(),
            $filtre->jusqua(),
            $filtre->page(),
            $filtre->parPage(),
        );

        return new JsonResponse([
            'evenements' => $this->normalizer->normaliserPlusieurs($resultat['lignes']),
            'total' => $resultat['total'],
            'page' => $filtre->page(),
            'parPage' => $filtre->parPage(),
        ]);
    }

    /** Vue d'ensemble : activité, masse monétaire, objets et vendeurs en tête. */
    #[Route("/tableau-de-bord", name: "tableau_de_bord", methods: ["POST"])]
    public function tableauDeBord(TableauDeBordService $tableauDeBord): Response
    {
        return new JsonResponse($tableauDeBord->tableauDeBord());
    }

    /** La liste des comptes pour le rail de l'écran « Joueurs ». */
    #[Route("/joueurs", name: "joueurs", methods: ["POST"])]
    public function joueurs(TableauDeBordService $tableauDeBord): Response
    {
        return new JsonResponse(['joueurs' => $tableauDeBord->joueurs()]);
    }

    /**
     * La fiche d'enquête d'un joueur : identité, cumuls, et ce qu'il a fait ET subi.
     *
     * C'est cet endpoint qui remplit enfin le `NavLink to="/administration/joueurs"` déclaré
     * sans route depuis l'origine.
     */
    #[Route("/joueur", name: "joueur", methods: ["POST"])]
    public function joueur(#[MapRequestPayload] JoueurFicheDTO $dto, UserRepository $userRepository, TableauDeBordService $tableauDeBord): Response
    {
        $joueur = $userRepository->find($dto->userId);
        if ($joueur === null) {
            return new JsonResponse(['message' => "Ce joueur n'existe pas."], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($tableauDeBord->fiche($joueur));
    }

    /**
     * Types, catégories et joueurs : de quoi peupler les filtres de l'écran.
     *
     * Le front ne connaît AUCUN type d'événement en dur — même discipline que
     * `QuestActionTypeConfig` pour le QuestMaker. Ajouter un type reste donc une
     * modification back seulement.
     */
    #[Route("/referentiels", name: "referentiels", methods: ["POST"])]
    public function referentiels(): Response
    {
        $joueurs = array_map(
            static fn (array $ligne) => ['id' => (int) $ligne['id'], 'pseudo' => (string) $ligne['pseudo']],
            $this->userRepository->createQueryBuilder('u')
                ->select('u.id, u.pseudo')
                ->orderBy('u.pseudo', 'ASC')
                ->getQuery()
                ->getArrayResult()
        );

        return new JsonResponse($this->normalizer->referentiels() + ['joueurs' => $joueurs]);
    }
}
