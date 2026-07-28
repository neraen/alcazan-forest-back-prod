<?php

namespace App\service;

use App\Entity\User;
use App\Repository\CarteCarreauRepository;
use App\Repository\DonjonInstanceMembreRepository;

/**
 * Vue de carte INSTANCIÉE — service de LECTURE seule.
 *
 * Le décor d'une salle de donjon reste une carte unique en base ; seule l'occupation
 * est virtualisée. Concrètement, sur une carte de donjon :
 *   - les joueurs joints depuis `carte_carreau.joueur_id` sont retirés (cette colonne
 *     est un OneToOne global : elle ne peut pas porter plusieurs groupes à la fois, et
 *     en instance on ne l'écrit plus du tout) ;
 *   - les membres présents de l'instance du joueur sont réinjectés à leur position,
 *     avec exactement les mêmes champs — le front ne voit aucune différence.
 *
 * Un joueur qui se retrouve sur une carte de donjon SANS instance active (durée max
 * dépassée pendant sa déconnexion) voit la salle vide et `ejection` renseignée : c'est
 * l'appelant qui décide de le reposer dehors.
 */
class DonjonMapView
{
    /** Champs de joueur produits par getAllCasesOfMap, à neutraliser puis réinjecter. */
    private const CHAMPS_JOUEUR = [
        'userId', 'pseudo', 'sexe', 'nomClasse', 'niveau',
        'nomAlignement', 'iconeAlignement', 'nomGuilde',
    ];

    public function __construct(
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly DonjonInstanceMembreRepository $membreRepository,
        private readonly DonjonInstanceService $instanceService,
        private readonly DonjonCombatService $combatService
    ) {}

    /**
     * Les cases de la carte telles que ce joueur doit les voir.
     *
     * @return array{cases: array, instanceId: ?int, ejection: ?array{carteId: int, abscisse: int, ordonnee: int}}
     */
    public function casesPourJoueur(User $user, int $carteId): array
    {
        $cases = $this->carteCarreauRepository->getAllCasesOfMap($carteId);

        $donjon = $this->instanceService->donjonDeLaCarte($carteId);
        if ($donjon === null) {
            return ['cases' => $cases, 'instanceId' => null, 'ejection' => null];
        }

        $instance = $this->instanceService->instanceCourante($user);
        if ($instance === null || $instance->getDonjon()->getId() !== $donjon->getId()) {
            $sortie = $donjon->getCarteSortie();

            return [
                'cases' => $this->sansJoueurs($cases),
                'instanceId' => null,
                'ejection' => $sortie === null ? null : [
                    'carteId' => $sortie->getId(),
                    'abscisse' => $donjon->getSortieAbscisse(),
                    'ordonnee' => $donjon->getSortieOrdonnee(),
                ],
            ];
        }

        $membres = $this->membreRepository->getMembresSurCarte($instance->getId(), $carteId);
        $vues = $this->avecMembres($this->sansJoueurs($cases), $membres);
        $vues = $this->avecRenforts($vues, $this->combatService->renfortsVivants($instance, $carteId));

        return [
            'cases' => $vues,
            'instanceId' => $instance->getId(),
            'ejection' => null,
        ];
    }

    /**
     * Les cases wrap de cette carte qui sont une PORTE D'ENTRÉE de donjon, indexées par
     * id de case. Le front y ouvre la modale de groupe au lieu de franchir directement,
     * sans avoir à interroger le serveur à chaque clic sur un passage.
     *
     * Une porte n'en est une que si elle mène vers un donjon AUTRE que celui où l'on se
     * trouve déjà : les passages internes (salle 1 → salle 2, et le retour) restent des
     * wraps ordinaires. Sans cette condition, circuler dans son propre donjon rouvrait la
     * modale d'entrée et annonçait « vous avez déjà fait ce donjon aujourd'hui ».
     *
     * @param int $carteId carte affichée (celle d'où partent ces cases)
     * @return array<int, int> carteCarreauId => donjonId
     */
    public function portesDeDonjon(array $cases, int $carteId): array
    {
        $portes = [];
        $donjonParCarte = [];
        $donjonCourantId = $this->instanceService->donjonDeLaCarte($carteId)?->getId();

        foreach ($cases as $case) {
            $cible = $case['targetMapId'] ?? null;
            if (!$case['isWrap'] || $cible === null) {
                continue;
            }

            if (!array_key_exists($cible, $donjonParCarte)) {
                $donjonParCarte[$cible] = $this->instanceService->donjonDeLaCarte((int)$cible)?->getId();
            }

            $donjonCible = $donjonParCarte[$cible];
            if ($donjonCible !== null && $donjonCible !== $donjonCourantId) {
                $portes[$case['carteCarreauId']] = $donjonCible;
            }
        }

        return $portes;
    }

    /**
     * Une case d'instance est-elle déjà occupée par un membre du groupe ?
     * En instance, `carte_carreau.joueur_id` ne fait plus foi : la collision se juge
     * entre membres présents de l'instance, et uniquement entre eux.
     */
    public function positionOccupeeDansInstance(
        int $instanceId,
        int $carteId,
        int $abscisse,
        int $ordonnee,
        int $saufUserId
    ): bool {
        foreach ($this->membreRepository->getMembresSurCarte($instanceId, $carteId) as $membre) {
            if ((int)$membre['userId'] === $saufUserId) {
                continue;
            }
            if ((int)$membre['abscisse'] === $abscisse && (int)$membre['ordonnee'] === $ordonnee) {
                return true;
            }
        }

        return false;
    }

    /** @param array $cases lignes de getAllCasesOfMap */
    private function sansJoueurs(array $cases): array
    {
        return array_map(static function (array $case): array {
            foreach (self::CHAMPS_JOUEUR as $champ) {
                $case[$champ] = null;
            }

            return $case;
        }, $cases);
    }

    /**
     * Pose les monstres d'instance sur la carte (population d'une salle, renforts d'un
     * boss). Ils ne sont PAS dans `monstre_carreau` (table attachée au décor, donc
     * partagée) : `renfortId` distingue une cible de donjon d'un monstre du monde ouvert,
     * le front et l'API de ciblage s'en servent.
     *
     * C'est le champ `renfortId` de LA CASE qui fait le ciblage automatique côté front
     * (exactement comme `hasMonstre` pour un monstre du monde ouvert) : il doit donc
     * descendre avec la carte à chaque déplacement, et pas seulement dans l'état de combat.
     */
    private function avecRenforts(array $cases, array $renforts): array
    {
        if ($renforts === []) {
            return $cases;
        }

        $parPosition = [];
        foreach ($renforts as $renfort) {
            $parPosition[$renfort->getAbscisse() . ':' . $renfort->getOrdonnee()] = $renfort;
        }

        foreach ($cases as $index => $case) {
            $renfort = $parPosition[$case['abscisse'] . ':' . $case['ordonnee']] ?? null;
            if ($renfort === null) {
                continue;
            }

            $cases[$index]['renfortId'] = $renfort->getId();
            $cases[$index]['renfortNom'] = $renfort->getMonstre()->getName();
            $cases[$index]['renfortSkin'] = $renfort->getMonstre()->getSkin();
            $cases[$index]['renfortVie'] = $renfort->getCurrentLife();
            $cases[$index]['renfortVieMax'] = $renfort->getMonstre()->getMaxLife();
        }

        return $cases;
    }

    private function avecMembres(array $cases, array $membres): array
    {
        if ($membres === []) {
            return $cases;
        }

        // Index (abscisse, ordonnee) -> membre, pour ne pas balayer la liste 384 fois.
        $parPosition = [];
        foreach ($membres as $membre) {
            $parPosition[$membre['abscisse'] . ':' . $membre['ordonnee']] = $membre;
        }

        foreach ($cases as $index => $case) {
            $membre = $parPosition[$case['abscisse'] . ':' . $case['ordonnee']] ?? null;
            if ($membre === null) {
                continue;
            }

            foreach (self::CHAMPS_JOUEUR as $champ) {
                $cases[$index][$champ] = $membre[$champ] ?? null;
            }
        }

        return $cases;
    }
}
