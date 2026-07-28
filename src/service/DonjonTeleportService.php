<?php

namespace App\service;

use App\Entity\Donjon;
use App\Entity\User;
use App\Exception\DonjonException;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\DonjonSalleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Déplace les joueurs à l'entrée et à la sortie d'un donjon.
 *
 * Isolé de DonjonInstanceService pour garder ce dernier libre de toute dépendance au
 * décor. La dissymétrie est volontaire :
 *  - DEHORS, l'occupation est portée par `carte_carreau.joueur_id` (on l'écrit) ;
 *  - EN INSTANCE, elle ne l'est jamais (on ne pose que `user.map/case_*`).
 */
class DonjonTeleportService
{
    public function __construct(
        private readonly CarteRepository $carteRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly DonjonSalleRepository $salleRepository,
        private readonly MapService $mapService,
        private readonly DonjonInstanceService $instanceService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Pose tout un groupe dans la salle d'entrée, chacun sur sa propre case.
     *
     * Le point d'arrivée est DÉDUIT du contenu : c'est la porte de retour de la salle
     * d'entrée (la case wrap qui vise la carte de sortie du donjon). Rien à configurer
     * en plus, et un donjon redécoupé dans le MapMaker reste cohérent.
     *
     * @param User[] $joueurs
     * @return array{carteId: int, positions: array<int, array{abscisse: int, ordonnee: int}>}
     */
    public function placerDansLaSalleDEntree(Donjon $donjon, array $joueurs): array
    {
        $salleEntree = $this->salleRepository->findEntree($donjon);
        if ($salleEntree === null) {
            throw new DonjonException("Le {$donjon->getNom()} n'a pas de salle d'entrée configurée.");
        }

        $carteId = $salleEntree->getCarte()->getId();
        $cases = $this->carteCarreauRepository->getAllCasesOfMap($carteId);
        $porteRetour = $this->porteDeRetour($cases, $donjon);
        $libres = $this->casesDAtterrissage($cases, $porteRetour, count($joueurs));

        if (count($libres) < count($joueurs)) {
            throw new DonjonException("La salle d'entrée du {$donjon->getNom()} est trop exiguë pour ce groupe.");
        }

        return $this->entityManager->wrapInTransaction(function () use ($joueurs, $carteId, $libres): array {
            $carte = $this->carteRepository->find($carteId);
            $positions = [];

            foreach ($joueurs as $index => $joueur) {
                $case = $libres[$index];
                // Le joueur venait du monde ouvert : sa case de décor doit être rendue.
                $this->carteCarreauRepository->updatePlayerInCase($joueur);
                $joueur->setMap($carte);
                $joueur->setCaseAbscisse($case['abscisse']);
                $joueur->setCaseOrdonnee($case['ordonnee']);
                $this->entityManager->persist($joueur);

                $positions[$joueur->getId()] = [
                    'abscisse' => $case['abscisse'],
                    'ordonnee' => $case['ordonnee'],
                ];
            }

            $this->entityManager->flush();

            return ['carteId' => $carteId, 'positions' => $positions];
        });
    }

    /**
     * @param array{carteId: int, abscisse: int, ordonnee: int} $sortie
     * @return array{carteId: int, abscisse: int, ordonnee: int} la position réellement occupée
     */
    public function reposerDehors(User $user, array $sortie): array
    {
        $this->instanceService->sortir($user);

        return $this->entityManager->wrapInTransaction(function () use ($user, $sortie): array {
            $carte = $this->carteRepository->find($sortie['carteId']);
            if ($carte === null) {
                return [
                    'carteId' => $user->getMap()->getId(),
                    'abscisse' => $user->getCaseAbscisse(),
                    'ordonnee' => $user->getCaseOrdonnee(),
                ];
            }

            $cases = $this->carteCarreauRepository->findByCoordonnee(
                $sortie['carteId'],
                $sortie['abscisse'],
                $sortie['ordonnee']
            );
            $case = $cases[0] ?? null;

            // Case de sortie occupée ou mal configurée : on retombe sur la première case
            // libre de la carte plutôt que d'empiler deux joueurs (contrainte unique).
            if ($case === null || $case->getJoueur() !== null) {
                $case = $this->premiereCaseLibre($sortie['carteId']) ?? $case;
            }

            $this->carteCarreauRepository->updatePlayerInCase($user);
            $user->setMap($carte);

            if ($case !== null) {
                $user->setCaseAbscisse($case->getAbscisse());
                $user->setCaseOrdonnee($case->getOrdonnee());
                $case->setJoueur($user);
                $this->entityManager->persist($case);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return [
                'carteId' => $carte->getId(),
                'abscisse' => $user->getCaseAbscisse(),
                'ordonnee' => $user->getCaseOrdonnee(),
            ];
        });
    }

    /** La case wrap de la salle d'entrée qui ramène vers la carte de sortie du donjon. */
    private function porteDeRetour(array $cases, Donjon $donjon): ?array
    {
        $carteSortieId = $donjon->getCarteSortie()?->getId();
        foreach ($cases as $case) {
            if ($case['isWrap'] && (int)$case['targetMapId'] === $carteSortieId) {
                return $case;
            }
        }

        // Pas de porte de retour identifiable : on se rabat sur la première case wrap.
        foreach ($cases as $case) {
            if ($case['isWrap']) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Les `$nombre` premières cases foulables autour du point d'arrivée. On n'écarte PAS
     * les cases portant un joueur : sur une carte d'instance, `carte_carreau.joueur_id`
     * est toujours nul et l'instance est neuve, donc la salle est vide par construction.
     */
    private function casesDAtterrissage(array $cases, ?array $porteRetour, int $nombre): array
    {
        if ($porteRetour === null) {
            return array_slice(array_values(array_filter($cases, $this->estFoulable(...))), 0, $nombre);
        }

        $candidats = array_merge(
            array_values($this->mapService->getAdjacentCase($cases, $porteRetour['carteCarreauId'])),
            array_values($this->mapService->getLargeAdjacentCase($cases, $porteRetour['carteCarreauId'])),
            array_values($cases)
        );

        $retenues = [];
        $vues = [];
        foreach ($candidats as $case) {
            if (isset($vues[$case['carteCarreauId']]) || !$this->estFoulable($case)) {
                continue;
            }
            $vues[$case['carteCarreauId']] = true;
            $retenues[] = $case;
            if (count($retenues) === $nombre) {
                break;
            }
        }

        return $retenues;
    }

    private function estFoulable(array $case): bool
    {
        return $case['isUsable'] && !$case['isWrap'] && $case['pnjName'] === null;
    }

    private function premiereCaseLibre(int $carteId)
    {
        foreach ($this->carteCarreauRepository->getAllCasesOfMap($carteId) as $case) {
            if ($case['userId'] === null && $case['isUsable'] && !$case['isWrap'] && $case['pnjName'] === null) {
                return $this->carteCarreauRepository->find($case['carteCarreauId']);
            }
        }

        return null;
    }
}
