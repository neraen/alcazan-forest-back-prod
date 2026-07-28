<?php

namespace App\service;

use App\Config\DonjonMecaniqueConfig;
use App\Entity\Donjon;
use App\Entity\DonjonMecanique;
use App\Entity\DonjonSalle;
use App\Enum\ConditionSalleDonjon;
use App\Enum\MecaniqueDonjon;
use App\Enum\StatutInstanceDonjon;
use App\Enum\TypeSalleDonjon;
use App\Exception\DonjonException;
use App\Repository\CarteRepository;
use App\Repository\DonjonInstanceRepository;
use App\Repository\DonjonMecaniqueRepository;
use App\Repository\DonjonRepository;
use App\Repository\DonjonSalleRepository;
use App\Repository\MonstreRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DonjonMaker : lecture et sauvegarde COMPLÈTE d'un donjon (fiche + salles + mécaniques),
 * en une transaction et avec des ids stables — même patron que QuestEditorService.
 *
 * « Ids stables » veut dire : on ne supprime pas tout pour recréer. Les lignes envoyées
 * avec un id sont mises à jour, celles sans id créées, celles absentes supprimées. Sans
 * ça, chaque sauvegarde ferait exploser les auto-increments et invaliderait les
 * `mecaniques_jouees` des instances en cours (qui référencent des ids de mécanique).
 */
class DonjonEditorService
{
    public function __construct(
        private readonly DonjonRepository $donjonRepository,
        private readonly DonjonSalleRepository $salleRepository,
        private readonly DonjonMecaniqueRepository $mecaniqueRepository,
        private readonly DonjonInstanceRepository $instanceRepository,
        private readonly CarteRepository $carteRepository,
        private readonly MonstreRepository $monstreRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    public function listDonjons(): array
    {
        return array_map(fn (Donjon $donjon) => [
            'id' => $donjon->getId(),
            'nom' => $donjon->getNom(),
            'actif' => $donjon->isActif(),
            'niveauMin' => $donjon->getNiveauMin(),
            'tailleGroupeMax' => $donjon->getTailleGroupeMax(),
            'salles' => $donjon->getSalles()->count(),
        ], $this->donjonRepository->findBy([], ['nom' => 'ASC']));
    }

    public function getDonjonForEditor(int $donjonId): array
    {
        $donjon = $this->donjonRepository->find($donjonId);
        if ($donjon === null) {
            throw new DonjonException("Donjon introuvable.");
        }

        $salles = $this->salleRepository->findBy(['donjon' => $donjon], ['ordre' => 'ASC']);
        $mecaniques = $this->mecaniqueRepository->findBy(['donjon' => $donjon], ['ordre' => 'ASC']);

        return [
            'donjon' => [
                'id' => $donjon->getId(),
                'nom' => $donjon->getNom(),
                'description' => $donjon->getDescription(),
                'icone' => $donjon->getIcone(),
                'niveauMin' => $donjon->getNiveauMin(),
                'tailleGroupeMax' => $donjon->getTailleGroupeMax(),
                'dureeMaxMinutes' => $donjon->getDureeMaxMinutes(),
                'heureReset' => $donjon->getHeureReset(),
                'actif' => $donjon->isActif(),
                'carteSortieId' => $donjon->getCarteSortie()?->getId(),
                'sortieAbscisse' => $donjon->getSortieAbscisse(),
                'sortieOrdonnee' => $donjon->getSortieOrdonnee(),
            ],
            'salles' => array_map(fn (DonjonSalle $salle) => [
                'id' => $salle->getId(),
                'carteId' => $salle->getCarte()?->getId(),
                'carteNom' => $salle->getCarte()?->getNom(),
                'ordre' => $salle->getOrdre(),
                'type' => $salle->getType()->value,
                'condition' => $salle->getCondition()->value,
                'conditionParams' => $salle->getConditionParams(),
                'monstreId' => $salle->getMonstre()?->getId(),
                'nombreMonstres' => $salle->getNombreMonstres(),
            ], $salles),
            'mecaniques' => array_map(fn (DonjonMecanique $mecanique) => [
                'id' => $mecanique->getId(),
                'type' => $mecanique->getType()->value,
                'vieMax' => $mecanique->getVieMax(),
                'vieMin' => $mecanique->getVieMin(),
                'cooldownSecondes' => $mecanique->getCooldownSecondes(),
                'params' => $mecanique->getParams(),
                'ordre' => $mecanique->getOrdre(),
                'actif' => $mecanique->isActif(),
                'annonce' => $mecanique->getAnnonce(),
            ], $mecaniques),
            'instancesEnCours' => $this->compterInstancesEnCours($donjon),
        ];
    }

    /** Cartes disponibles + monstres, pour peupler les selects de l'éditeur. */
    public function getReferentiels(): array
    {
        $prises = [];
        foreach ($this->salleRepository->findAll() as $salle) {
            $prises[$salle->getCarte()?->getId()] = $salle->getDonjon()?->getId();
        }

        return [
            'cartes' => array_map(fn ($carte) => [
                'id' => $carte->getId(),
                'nom' => $carte->getNom(),
                // Une carte n'appartient qu'à UN donjon : le front grise les autres.
                'donjonId' => $prises[$carte->getId()] ?? null,
            ], $this->carteRepository->findBy([], ['nom' => 'ASC'])),
            'monstres' => array_map(fn ($monstre) => [
                'id' => $monstre->getId(),
                'nom' => $monstre->getName(),
            ], $this->monstreRepository->findBy([], ['name' => 'ASC'])),
            'typesDeSalle' => DonjonMecaniqueConfig::typesDeSalle(),
            'conditionsDeSalle' => DonjonMecaniqueConfig::conditionsDeSalle(),
        ];
    }

    public function getMecaniqueConfig(): array
    {
        return DonjonMecaniqueConfig::all();
    }

    /* ------------------------------------------------------------------ */
    /* Sauvegarde                                                          */
    /* ------------------------------------------------------------------ */

    public function saveDonjon(array $data): array
    {
        $donjonId = $this->entityManager->wrapInTransaction(function () use ($data): int {
            $donjon = $this->upsertDonjon($data);
            $this->upsertSalles($donjon, $data['salles'] ?? []);
            $this->upsertMecaniques($donjon, $data['mecaniques'] ?? []);
            $this->entityManager->flush();

            return $donjon->getId();
        });

        return $this->getDonjonForEditor($donjonId);
    }

    public function deleteDonjon(int $donjonId): void
    {
        $donjon = $this->donjonRepository->find($donjonId);
        if ($donjon === null) {
            throw new DonjonException("Donjon introuvable.");
        }

        // Les instances (et leurs verrous) référencent le donjon : les supprimer, ce serait
        // effacer la partie en cours de joueurs connectés. On refuse plutôt que de casser.
        if ($this->compterInstancesEnCours($donjon) > 0) {
            throw new DonjonException(
                "Des groupes sont actuellement dans ce donjon. Désactivez-le et réessayez plus tard."
            );
        }
        if ($this->instanceRepository->count(['donjon' => $donjon]) > 0) {
            throw new DonjonException(
                "Ce donjon a un historique d'expéditions : désactivez-le au lieu de le supprimer."
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($donjon): void {
            foreach ($this->mecaniqueRepository->findBy(['donjon' => $donjon]) as $mecanique) {
                $this->entityManager->remove($mecanique);
            }
            foreach ($this->salleRepository->findBy(['donjon' => $donjon]) as $salle) {
                $this->entityManager->remove($salle);
            }
            $this->entityManager->remove($donjon);
            $this->entityManager->flush();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function upsertDonjon(array $data): Donjon
    {
        $donjon = isset($data['id']) ? $this->donjonRepository->find((int)$data['id']) : new Donjon();
        if ($donjon === null) {
            throw new DonjonException("Donjon introuvable.");
        }

        $nom = trim((string)($data['nom'] ?? ''));
        if ($nom === '') {
            throw new DonjonException("Le donjon doit avoir un nom.");
        }

        $tailleGroupe = (int)($data['tailleGroupeMax'] ?? 5);
        if ($tailleGroupe < 1) {
            throw new DonjonException("La taille de groupe doit valoir au moins 1.");
        }

        $heureReset = (int)($data['heureReset'] ?? 5);
        if ($heureReset < 0 || $heureReset > 23) {
            throw new DonjonException("L'heure de reset doit être comprise entre 0 et 23.");
        }

        $donjon
            ->setNom($nom)
            ->setDescription($data['description'] ?? null)
            ->setIcone($data['icone'] ?? null)
            ->setNiveauMin(max(0, (int)($data['niveauMin'] ?? 0)))
            ->setTailleGroupeMax($tailleGroupe)
            ->setDureeMaxMinutes(max(0, (int)($data['dureeMaxMinutes'] ?? 0)))
            ->setHeureReset($heureReset)
            ->setActif((bool)($data['actif'] ?? true))
            ->setSortieAbscisse(max(0, (int)($data['sortieAbscisse'] ?? 0)))
            ->setSortieOrdonnee(max(0, (int)($data['sortieOrdonnee'] ?? 0)));

        $carteSortieId = $data['carteSortieId'] ?? null;
        $donjon->setCarteSortie($carteSortieId === null ? null : $this->carteRepository->find((int)$carteSortieId));

        $this->entityManager->persist($donjon);
        $this->entityManager->flush(); // id nécessaire pour rattacher salles et mécaniques

        return $donjon;
    }

    private function upsertSalles(Donjon $donjon, array $sallesData): void
    {
        if ($sallesData === []) {
            throw new DonjonException("Un donjon doit comporter au moins une salle.");
        }

        $existantes = [];
        foreach ($this->salleRepository->findBy(['donjon' => $donjon]) as $salle) {
            $existantes[$salle->getId()] = $salle;
        }

        $cartesVues = [];
        $conservees = [];
        $ordre = 1;

        foreach ($sallesData as $data) {
            $carteId = (int)($data['carteId'] ?? 0);
            $carte = $this->carteRepository->find($carteId);
            if ($carte === null) {
                throw new DonjonException("Salle {$ordre} : carte introuvable.");
            }
            if (isset($cartesVues[$carteId])) {
                throw new DonjonException("La carte « {$carte->getNom()} » est présente deux fois dans le plan.");
            }

            // Index unique en base : une carte n'appartient qu'à un donjon. On le vérifie
            // ici pour rendre un message clair au lieu d'une violation de contrainte.
            $ailleurs = $this->salleRepository->findOneByCarte($carteId);
            if ($ailleurs !== null && $ailleurs->getDonjon()?->getId() !== $donjon->getId()) {
                throw new DonjonException(
                    "La carte « {$carte->getNom()} » appartient déjà au donjon « {$ailleurs->getDonjon()->getNom()} »."
                );
            }
            $cartesVues[$carteId] = true;

            $id = isset($data['id']) ? (int)$data['id'] : null;
            $salle = $id !== null && isset($existantes[$id]) ? $existantes[$id] : new DonjonSalle();
            $condition = ConditionSalleDonjon::tryFrom((string)($data['condition'] ?? ''))
                ?? ConditionSalleDonjon::AUCUNE;

            $monstreId = $data['monstreId'] ?? null;
            $monstre = $monstreId === null ? null : $this->monstreRepository->find((int)$monstreId);
            if ($monstreId !== null && $monstre === null) {
                throw new DonjonException("Salle {$ordre} : monstre introuvable.");
            }

            $salle
                ->setDonjon($donjon)
                ->setCarte($carte)
                ->setOrdre($ordre)
                ->setType(TypeSalleDonjon::tryFrom((string)($data['type'] ?? '')) ?? TypeSalleDonjon::COULOIR)
                ->setCondition($condition)
                ->setConditionParams($this->nettoyerConditionParams($condition, $data['conditionParams'] ?? []))
                ->setMonstre($monstre)
                ->setNombreMonstres((int)($data['nombreMonstres'] ?? 0));

            // Une salle qui exige d'être nettoyée sans population devant elle serait un
            // passage libre déguisé : mieux vaut le dire à l'admin que le laisser croire.
            if ($condition === ConditionSalleDonjon::SALLE_NETTOYEE && $ordre === 1) {
                throw new DonjonException(
                    "La salle d'entrée ne peut pas exiger d'avoir nettoyé la salle précédente."
                );
            }

            $this->entityManager->persist($salle);
            if ($salle->getId() !== null) {
                $conservees[$salle->getId()] = true;
            }
            $ordre++;
        }

        foreach ($existantes as $id => $salle) {
            if (!isset($conservees[$id])) {
                $this->entityManager->remove($salle);
            }
        }
    }

    private function upsertMecaniques(Donjon $donjon, array $mecaniquesData): void
    {
        $existantes = [];
        foreach ($this->mecaniqueRepository->findBy(['donjon' => $donjon]) as $mecanique) {
            $existantes[$mecanique->getId()] = $mecanique;
        }

        $conservees = [];
        $ordre = 1;

        foreach ($mecaniquesData as $data) {
            $type = MecaniqueDonjon::tryFrom((string)($data['type'] ?? ''));
            if ($type === null) {
                throw new DonjonException("Mécanique {$ordre} : type inconnu.");
            }

            $vieMax = (int)($data['vieMax'] ?? 100);
            $vieMin = (int)($data['vieMin'] ?? 0);
            if ($vieMin > $vieMax) {
                throw new DonjonException(
                    "Mécanique « {$type->label()} » : la borne basse de vie doit être inférieure à la borne haute."
                );
            }

            $id = isset($data['id']) ? (int)$data['id'] : null;
            $mecanique = $id !== null && isset($existantes[$id]) ? $existantes[$id] : new DonjonMecanique();
            $mecanique
                ->setDonjon($donjon)
                ->setType($type)
                ->setVieMax(max(0, min(100, $vieMax)))
                ->setVieMin(max(0, min(100, $vieMin)))
                ->setCooldownSecondes(max(0, (int)($data['cooldownSecondes'] ?? 0)))
                ->setParams($this->nettoyerParams($type, $data['params'] ?? []))
                ->setOrdre($ordre)
                ->setActif((bool)($data['actif'] ?? true))
                ->setAnnonce(($data['annonce'] ?? null) ?: null);

            $this->entityManager->persist($mecanique);
            if ($mecanique->getId() !== null) {
                $conservees[$mecanique->getId()] = true;
            }
            $ordre++;
        }

        foreach ($existantes as $id => $mecanique) {
            if (!isset($conservees[$id])) {
                $this->entityManager->remove($mecanique);
            }
        }
    }

    /**
     * Ne garde que les paramètres connus du type, et complète les manquants par les
     * défauts de l'enum : une mécanique enregistrée est toujours exécutable, même si
     * le formulaire a été soumis incomplet.
     */
    /** Même contrat que nettoyerParams : clés inconnues écartées, manquantes complétées. */
    private function nettoyerConditionParams(ConditionSalleDonjon $condition, mixed $params): array
    {
        $params = is_array($params) ? $params : [];
        $propre = [];
        foreach ($condition->parametres() as $cle => $defaut) {
            $propre[$cle] = (int)($params[$cle] ?? $defaut);
        }

        return $propre;
    }

    private function nettoyerParams(MecaniqueDonjon $type, mixed $params): array
    {
        $params = is_array($params) ? $params : [];
        $propre = [];

        foreach ($type->parametres() as $cle => $defaut) {
            $valeur = $params[$cle] ?? $defaut;
            $propre[$cle] = is_float($defaut) ? (float)$valeur : (is_int($defaut) ? (int)$valeur : $valeur);
        }

        if ($type === MecaniqueDonjon::ADDS && ($propre['monstreId'] ?? null)) {
            if ($this->monstreRepository->find((int)$propre['monstreId']) === null) {
                throw new DonjonException("Mécanique « {$type->label()} » : monstre introuvable.");
            }
        }

        return $propre;
    }

    private function compterInstancesEnCours(Donjon $donjon): int
    {
        return $this->instanceRepository->count([
            'donjon' => $donjon,
            'statut' => StatutInstanceDonjon::EN_COURS,
        ]);
    }
}
