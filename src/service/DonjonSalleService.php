<?php

namespace App\service;

use App\Entity\DonjonInstance;
use App\Entity\DonjonInstanceMonstre;
use App\Entity\DonjonInstanceSalle;
use App\Entity\DonjonSalle;
use App\Entity\User;
use App\Enum\ConditionSalleDonjon;
use App\Exception\DonjonException;
use App\Repository\CarteCarreauRepository;
use App\Repository\DonjonInstanceLevierRepository;
use App\Repository\DonjonInstanceMonstreRepository;
use App\Repository\DonjonInstanceSalleRepository;
use App\Repository\DonjonSalleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine à états de la progression SALLE PAR SALLE : conditions de passage et
 * population des salles. Personne d'autre n'écrit dans `donjon_instance_salle`.
 *
 * Deux principes :
 *
 *  1. **Une porte ouverte le reste** (`donjon_instance_salle.ouverte`). On ne refait pas
 *     l'énigme à chaque aller-retour, et surtout un joueur qui revient sur ses pas n'est
 *     jamais enfermé derrière une condition qu'il ne peut plus remplir.
 *
 *  2. **Une salle ne se peuple qu'une fois par expédition** (`peuplee`). Sans ce drapeau,
 *     sortir et revenir referait apparaître les monstres — une ferme à XP à volonté.
 *
 * La population va dans `donjon_instance_monstre`, JAMAIS dans `monstre_carreau` : cette
 * dernière est attachée au décor, donc partagée par tous les groupes (même défaut que
 * `carte_carreau.joueur_id`).
 */
class DonjonSalleService
{
    public function __construct(
        private readonly DonjonSalleRepository $salleRepository,
        private readonly DonjonInstanceSalleRepository $instanceSalleRepository,
        private readonly DonjonInstanceMonstreRepository $monstreInstanceRepository,
        private readonly DonjonInstanceLevierRepository $levierRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly MapService $mapService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Passage                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Le groupe peut-il entrer dans la salle qui porte cette carte ?
     * Jette une DonjonException avec un message joueur si la condition n'est pas remplie.
     */
    public function verifierPassage(DonjonInstance $instance, int $carteCible): void
    {
        $salle = $this->salleRepository->findOneByCarte($carteCible);
        if ($salle === null || $salle->getCondition() === ConditionSalleDonjon::AUCUNE) {
            return;
        }

        // Déjà franchie : la porte reste ouverte pour toute l'expédition.
        if ($this->etatSalle($instance, $salle)->isOuverte()) {
            return;
        }

        if (!$this->conditionRemplie($instance, $salle)) {
            throw new DonjonException($this->messageDeRefus($instance, $salle));
        }

        $this->ouvrir($instance, $salle);
    }

    /** La condition d'entrée de cette salle est-elle satisfaite ? */
    public function conditionRemplie(DonjonInstance $instance, DonjonSalle $salle): bool
    {
        return match ($salle->getCondition()) {
            ConditionSalleDonjon::AUCUNE => true,
            ConditionSalleDonjon::SALLE_NETTOYEE => $this->salleNettoyee($instance, $salle),
            ConditionSalleDonjon::LEVIERS => $this->leviersActionnes($instance, $salle),
            ConditionSalleDonjon::BOSS_VAINCU => ($instance->getBossCurrentLife() ?? null) === 0,
        };
    }

    /** Marque la porte comme franchie : elle ne se refermera plus de l'expédition. */
    public function ouvrir(DonjonInstance $instance, DonjonSalle $salle): void
    {
        $etat = $this->etatSalle($instance, $salle);
        if ($etat->isOuverte()) {
            return;
        }

        $etat->setOuverte(true);
        $this->entityManager->persist($etat);
        $this->entityManager->flush();
    }

    /**
     * Un levier vient d'être actionné : si la salle SUIVANTE est commandée par des leviers
     * et que l'énigme est résolue, la porte s'ouvre définitivement.
     *
     * @return ?string message de succès, ou null si aucune porte n'a bougé
     */
    public function resoudreLeviersDeLaPorte(DonjonInstance $instance, User $user): ?string
    {
        $salleCourante = $this->salleRepository->findOneByCarte((int)$user->getMap()?->getId());
        if ($salleCourante === null) {
            return null;
        }

        $suivante = $this->salleSuivante($instance, $salleCourante);
        if ($suivante === null || $suivante->getCondition() !== ConditionSalleDonjon::LEVIERS) {
            return null;
        }
        if ($this->etatSalle($instance, $suivante)->isOuverte()) {
            return null;
        }
        if (!$this->leviersActionnes($instance, $suivante)) {
            return null;
        }

        $this->ouvrir($instance, $suivante);

        return "Un grondement : le passage vers {$suivante->getCarte()->getNom()} s'ouvre.";
    }

    /* ------------------------------------------------------------------ */
    /* Population                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Fait apparaître la population de la salle à la première arrivée du groupe.
     * Idempotent : rappelé à chaque entrée, il ne peuple qu'une fois.
     *
     * @return ?string annonce, ou null si rien n'est apparu
     */
    public function peupler(DonjonInstance $instance, int $carteId): ?string
    {
        $salle = $this->salleRepository->findOneByCarte($carteId);
        if ($salle === null || !$salle->aUnePopulation()) {
            return null;
        }

        $etat = $this->etatSalle($instance, $salle);
        if ($etat->isPeuplee()) {
            return null;
        }

        $positions = $this->casesLibres($carteId, $salle->getNombreMonstres(), $instance, $salle);
        foreach ($positions as $position) {
            $monstre = (new DonjonInstanceMonstre())
                ->setInstance($instance)
                ->setMonstre($salle->getMonstre())
                ->setCarteId($carteId)
                ->setAbscisse($position['abscisse'])
                ->setOrdonnee($position['ordonnee'])
                ->setCurrentLife($salle->getMonstre()->getMaxLife());

            $this->entityManager->persist($monstre);
        }

        $etat->setPeuplee(true);
        $this->entityManager->persist($etat);
        $this->entityManager->flush();

        $nombre = count($positions);
        $nom = $salle->getMonstre()->getName();

        // Cette annonce n'est pas décorative : comme les monstres du monde ouvert, ceux
        // d'une salle ne sont PAS dessinés sur la carte (on les cible en marchant sur leur
        // case). C'est le seul indice qu'il y a quelque chose à nettoyer ici.
        return match (true) {
            $nombre === 0 => null,
            $nombre === 1 => "{$nom} vous barre la route.",
            default => "{$nombre} {$nom} vous barrent la route.",
        };
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    /** La condition porte sur la salle qu'on QUITTE : celle qui précède dans l'ordre. */
    private function sallePrecedente(DonjonInstance $instance, DonjonSalle $salle): ?DonjonSalle
    {
        $precedente = null;
        foreach ($this->sallesOrdonnees($instance) as $candidate) {
            if ($candidate->getId() === $salle->getId()) {
                return $precedente;
            }
            $precedente = $candidate;
        }

        return null;
    }

    private function salleSuivante(DonjonInstance $instance, DonjonSalle $salle): ?DonjonSalle
    {
        $trouvee = false;
        foreach ($this->sallesOrdonnees($instance) as $candidate) {
            if ($trouvee) {
                return $candidate;
            }
            if ($candidate->getId() === $salle->getId()) {
                $trouvee = true;
            }
        }

        return null;
    }

    /** @return DonjonSalle[] */
    private function sallesOrdonnees(DonjonInstance $instance): array
    {
        return $this->salleRepository->findBy(['donjon' => $instance->getDonjon()], ['ordre' => 'ASC']);
    }

    private function salleNettoyee(DonjonInstance $instance, DonjonSalle $salle): bool
    {
        $precedente = $this->sallePrecedente($instance, $salle);
        if ($precedente === null) {
            return true;
        }

        return $this->monstreInstanceRepository->count([
            'instance' => $instance,
            'carteId' => $precedente->getCarte()->getId(),
            'vivant' => true,
        ]) === 0;
    }

    /**
     * Leviers de la salle précédente actionnés par des joueurs DIFFÉRENTS dans la même
     * fenêtre : c'est la simultanéité qui force la coordination, pas le nombre.
     */
    private function leviersActionnes(DonjonInstance $instance, DonjonSalle $salle): bool
    {
        $precedente = $this->sallePrecedente($instance, $salle);
        if ($precedente === null) {
            return true;
        }

        $requis = max(1, (int)$salle->conditionParam('leviers'));
        $fenetre = max(1, (int)$salle->conditionParam('fenetreSecondes'));
        $limite = (new \DateTimeImmutable())->modify("-{$fenetre} seconds");
        $carteId = $precedente->getCarte()->getId();

        $cases = [];
        $joueurs = [];
        foreach ($this->levierRepository->findBy(['instance' => $instance]) as $levier) {
            if ($levier->getActionneAt() < $limite) {
                continue;
            }
            $case = $this->carteCarreauRepository->find($levier->getCarteCarreauId());
            if ($case?->getCarte()?->getId() !== $carteId) {
                continue;
            }
            $cases[$levier->getCarteCarreauId()] = true;
            $joueurs[$levier->getActionnePar()?->getId()] = true;
        }

        return count($cases) >= $requis && count($joueurs) >= $requis;
    }

    private function messageDeRefus(DonjonInstance $instance, DonjonSalle $salle): string
    {
        $message = $salle->getCondition()->refus();

        if ($salle->getCondition() === ConditionSalleDonjon::SALLE_NETTOYEE) {
            $precedente = $this->sallePrecedente($instance, $salle);
            $restants = $precedente === null ? 0 : $this->monstreInstanceRepository->count([
                'instance' => $instance,
                'carteId' => $precedente->getCarte()->getId(),
                'vivant' => true,
            ]);
            if ($restants > 0) {
                $message .= " Il en reste {$restants}.";
            }
        }

        if ($salle->getCondition() === ConditionSalleDonjon::LEVIERS) {
            $requis = max(1, (int)$salle->conditionParam('leviers'));
            $message .= " Il en faut {$requis}, actionnés en même temps par des joueurs différents.";
        }

        return $message;
    }

    private function etatSalle(DonjonInstance $instance, DonjonSalle $salle): DonjonInstanceSalle
    {
        $etat = $this->instanceSalleRepository->findOneBy(['instance' => $instance, 'salle' => $salle]);
        if ($etat === null) {
            $etat = (new DonjonInstanceSalle())->setInstance($instance)->setSalle($salle);
            $this->entityManager->persist($etat);
            $this->entityManager->flush();
        }

        return $etat;
    }

    /**
     * Où faire apparaître la population.
     *
     * Les monstres sont placés DEVANT LA SORTIE vers la salle suivante — ils « barrent la
     * route », ce qui est littéralement leur raison d'être quand la salle suivante exige
     * d'avoir été nettoyée. Prendre les premières cases de la liste (ce que faisait la
     * première version) les envoyait dans le coin haut-gauche de la carte, hors de la
     * pièce dessinée : invisibles et sans effet sur le parcours.
     *
     * @return array<int, array{abscisse: int, ordonnee: int}>
     */
    private function casesLibres(int $carteId, int $combien, DonjonInstance $instance, DonjonSalle $salle): array
    {
        $cases = $this->carteCarreauRepository->getAllCasesOfMap($carteId);
        $sortie = $this->porteVersLaSuivante($cases, $instance, $salle);

        $candidats = $sortie === null
            ? array_values($cases)
            : array_merge(
                array_values($this->mapService->getAdjacentCase($cases, $sortie['carteCarreauId'])),
                array_values($this->mapService->getLargeAdjacentCase($cases, $sortie['carteCarreauId'])),
                array_values($cases)
            );

        $retenues = [];
        $vues = [];
        foreach ($candidats as $case) {
            if (count($retenues) >= $combien) {
                break;
            }
            if (isset($vues[$case['carteCarreauId']])) {
                continue;
            }
            // Sur une carte d'instance, `joueur_id` est toujours nul : la salle est vide
            // par construction à la première arrivée du groupe.
            if (!$case['isUsable'] || $case['isWrap'] || $case['pnjName'] !== null || $case['bossName'] !== null) {
                continue;
            }
            $vues[$case['carteCarreauId']] = true;
            $retenues[] = ['abscisse' => $case['abscisse'], 'ordonnee' => $case['ordonnee']];
        }

        return $retenues;
    }

    /** La case wrap de cette salle qui mène à la salle suivante du donjon. */
    private function porteVersLaSuivante(array $cases, DonjonInstance $instance, DonjonSalle $salle): ?array
    {
        $suivante = $this->salleSuivante($instance, $salle);
        $carteSuivante = $suivante?->getCarte()?->getId();
        if ($carteSuivante === null) {
            return null;
        }

        foreach ($cases as $case) {
            if ($case['isWrap'] && (int)$case['targetMapId'] === $carteSuivante) {
                return $case;
            }
        }

        return null;
    }
}
