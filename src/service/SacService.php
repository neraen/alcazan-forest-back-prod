<?php

namespace App\service;

use App\Entity\Consommable;
use App\Entity\Equipement;
use App\Entity\Inventaire;
use App\Entity\InventaireConsommable;
use App\Entity\InventaireEquipement;
use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\ReservationRessource;
use App\Entity\User;
use App\Enum\TypeItem;
use App\Enum\TypeRessource;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\ObjetRepository;
use App\Repository\ReservationRessourceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée UNIQUE des mutations de ressources d'un joueur : piles d'items du sac
 * (équipements, consommables, objets) et or. Aucun autre code ne doit toucher `quantity`
 * des lignes d'inventaire ni `user.money` directement.
 *
 * Réservations : une quantité peut être mise de côté pour une opération en cours (échange…)
 * via `reservation_ressource`. Le « disponible » (possédé − réservé) est ce que contrôlent
 * `retirerItem` et `debiterOr` : une ressource réservée ne peut être ni vendue, ni consommée,
 * ni équipée, ni dépensée ailleurs.
 *
 * Ce service ne flush JAMAIS : chaque méthode est conçue pour être appelée depuis une
 * transaction englobante (`$entityManager->wrapInTransaction(...)`) chez l'appelant, qui
 * reste responsable du commit — même règle que EquipementEquipeService/VenteService.
 */
class SacService
{
    public function __construct(
        private readonly EntityManagerInterface          $entityManager,
        private readonly InventaireRepository            $inventaireRepository,
        private readonly InventaireEquipementRepository  $inventaireEquipementRepository,
        private readonly InventaireConsommableRepository $inventaireConsommableRepository,
        private readonly InventaireObjetRepository       $inventaireObjetRepository,
        private readonly EquipementRepository            $equipementRepository,
        private readonly ConsommableRepository           $consommableRepository,
        private readonly ObjetRepository                 $objetRepository,
        private readonly ReservationRessourceRepository  $reservationRessourceRepository
    ) {}

    /* ------------------------------------------------------------------ items */

    /**
     * Empile `$quantite` exemplaires dans le sac (ligne créée si besoin).
     *
     * @throws \DomainException quantité invalide, item inconnu ou inventaire absent
     */
    public function ajouterItem(User $user, TypeItem $type, int $itemId, int $quantite): void
    {
        if ($quantite < 1) {
            throw new \DomainException("Quantité invalide.");
        }

        $inventaire = $this->getInventaire($user);
        $ligne = $this->trouverLigne($inventaire, $type, $itemId);

        if ($ligne !== null) {
            $ligne->setQuantity($ligne->getQuantity() + $quantite);

            return;
        }

        $item = $this->trouverItem($type, $itemId);
        if ($item === null) {
            throw new \DomainException("Cet objet n'existe pas.");
        }

        $ligne = match ($type) {
            TypeItem::EQUIPEMENT => (new InventaireEquipement())->setEquipement($item),
            TypeItem::CONSOMMABLE => (new InventaireConsommable())->setConsommable($item),
            TypeItem::OBJET => (new InventaireObjet())->setObjet($item),
        };
        $ligne->setInventaire($inventaire);
        $ligne->setQuantity($quantite);
        $this->entityManager->persist($ligne);
    }

    /**
     * Retire `$quantite` exemplaires du sac, dans la limite du DISPONIBLE (possédé − réservé).
     * La ligne est supprimée quand la pile tombe à zéro.
     *
     * @throws \DomainException quantité invalide, item absent ou disponible insuffisant
     */
    public function retirerItem(User $user, TypeItem $type, int $itemId, int $quantite): void
    {
        if ($quantite < 1) {
            throw new \DomainException("Quantité invalide.");
        }

        $inventaire = $this->getInventaire($user);
        $ligne = $this->trouverLigne($inventaire, $type, $itemId);
        if ($ligne === null || $ligne->getQuantity() < 1) {
            throw new \DomainException("Cet objet n'est pas dans votre inventaire.");
        }

        $reservee = $this->reservationRessourceRepository
            ->sommeReservee($user, TypeRessource::fromTypeItem($type), $itemId);
        $disponible = $ligne->getQuantity() - $reservee;

        if ($quantite > $disponible) {
            throw new \DomainException($reservee > 0
                ? sprintf("Seuls %d exemplaires sont disponibles, le reste est réservé.", max(0, $disponible))
                : sprintf("Vous n'en possédez que %d.", $ligne->getQuantity()));
        }

        if ($ligne->getQuantity() > $quantite) {
            $ligne->setQuantity($ligne->getQuantity() - $quantite);

            return;
        }

        $this->entityManager->remove($ligne);
    }

    public function quantitePossedee(User $user, TypeItem $type, int $itemId): int
    {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        if ($inventaire === null) {
            return 0;
        }

        $ligne = $this->trouverLigne($inventaire, $type, $itemId);

        return $ligne === null ? 0 : (int) $ligne->getQuantity();
    }

    public function quantiteDisponible(User $user, TypeItem $type, int $itemId): int
    {
        return $this->quantitePossedee($user, $type, $itemId)
            - $this->reservationRessourceRepository->sommeReservee($user, TypeRessource::fromTypeItem($type), $itemId);
    }

    /* --------------------------------------------------------------------- or */

    /** @throws \DomainException montant invalide */
    public function crediterOr(User $user, int $montant): void
    {
        if ($montant < 0) {
            throw new \DomainException("Montant invalide.");
        }

        $user->setMoney($user->getMoney() + $montant);
        $this->entityManager->persist($user);
    }

    /**
     * Débite l'or du joueur, dans la limite de l'or DISPONIBLE (solde − réservations).
     *
     * @throws \DomainException montant invalide ou or disponible insuffisant
     */
    public function debiterOr(User $user, int $montant): void
    {
        if ($montant < 0) {
            throw new \DomainException("Montant invalide.");
        }

        if ($montant > $this->orDisponible($user)) {
            throw new \DomainException("Vous n'avez pas assez d'or.");
        }

        $user->setMoney($user->getMoney() - $montant);
        $this->entityManager->persist($user);
    }

    public function orDisponible(User $user): int
    {
        return (int) $user->getMoney()
            - $this->reservationRessourceRepository->sommeReservee($user, TypeRessource::OR, 0);
    }

    /* ------------------------------------------------------------ réservations */

    /**
     * Fixe la quantité réservée par `$origine/$origineId` sur une ressource du joueur
     * (valeur ABSOLUE pour cette origine ; 0 supprime la réservation). Pour l'or,
     * utiliser `TypeRessource::OR` avec `$itemId = 0`.
     *
     * @throws \DomainException si le disponible (hors cette réservation) ne couvre pas la demande
     */
    public function reserver(
        User $user,
        TypeRessource $type,
        int $itemId,
        int $quantite,
        string $origine,
        int $origineId
    ): void {
        if ($quantite < 0) {
            throw new \DomainException("Quantité invalide.");
        }

        $reservation = $this->reservationRessourceRepository->findOneBy([
            'user' => $user,
            'type' => $type,
            'itemId' => $itemId,
            'origine' => $origine,
            'origineId' => $origineId,
        ]);

        $possede = $type === TypeRessource::OR
            ? (int) $user->getMoney()
            : $this->quantitePossedee($user, TypeItem::from($type->value), $itemId);
        $reserveAilleurs = $this->reservationRessourceRepository->sommeReservee($user, $type, $itemId)
            - ($reservation === null ? 0 : $reservation->getQuantite());

        if ($quantite > $possede - $reserveAilleurs) {
            throw new \DomainException($type === TypeRessource::OR
                ? "Vous n'avez pas assez d'or."
                : "Vous ne possédez pas cette quantité.");
        }

        if ($quantite === 0) {
            if ($reservation !== null) {
                $this->entityManager->remove($reservation);
            }

            return;
        }

        if ($reservation === null) {
            $reservation = (new ReservationRessource())
                ->setUser($user)
                ->setType($type)
                ->setItemId($itemId)
                ->setOrigine($origine)
                ->setOrigineId($origineId);
            $this->entityManager->persist($reservation);
        }

        $reservation->setQuantite($quantite);
    }

    /** Libère TOUTES les réservations d'une origine. Idempotent : ne rien trouver n'est pas une erreur. */
    public function libererReservations(string $origine, int $origineId): void
    {
        foreach ($this->reservationRessourceRepository->findByOrigine($origine, $origineId) as $reservation) {
            $this->entityManager->remove($reservation);
        }
    }

    /* ---------------------------------------------------------------- lecture */

    /**
     * Fiche affichable d'un modèle d'item — les trois familles nomment leurs champs
     * différemment (`nom`/`name`, `icone`/`image`, `prixRevente`/`prix_vente`) et deux d'entre
     * elles n'ont ni position ni rareté. C'est le SEUL endroit qui connaît ces divergences :
     * tout ce qui doit afficher un item par (type, id) passe par ici plutôt que de refaire un
     * quatrième `match` sur les trois familles.
     *
     * Contrat côté joueur : « pas de prix renseigné => 0 pièce d'or ».
     *
     * `position` et `rarete` sont nuls hors équipement, et `icone` est le nom de fichier BRUT :
     * les chemins d'images vivent côté front (`itemUtils.itemImage()`), jamais ici.
     *
     * @return array{nom: string, icone: ?string, prixRevente: int, description: ?string,
     *               position: ?string, rarete: ?string}
     * @throws \DomainException item inconnu
     */
    public function decrireItem(TypeItem $type, int $itemId): array
    {
        $item = $this->trouverItem($type, $itemId);
        if ($item === null) {
            throw new \DomainException("Cet objet n'existe pas.");
        }

        return match ($type) {
            TypeItem::EQUIPEMENT => [
                'nom' => (string) $item->getNom(),
                'icone' => $item->getIcone(),
                'prixRevente' => max(0, (int) $item->getPrixRevente()),
                'description' => $item->getDescription(),
                'position' => $item->getPositionEquipement()?->getName(),
                'rarete' => $item->getRarity()?->getName(),
            ],
            TypeItem::CONSOMMABLE => [
                'nom' => (string) $item->getNom(),
                'icone' => $item->getIcone(),
                'prixRevente' => max(0, (int) $item->getPrixRevente()),
                'description' => $item->getDescription(),
                'position' => null,
                'rarete' => null,
            ],
            TypeItem::OBJET => [
                'nom' => (string) $item->getName(),
                'icone' => $item->getImage(),
                'prixRevente' => max(0, (int) $item->getPrixVente()),
                'description' => $item->getDescription(),
                'position' => null,
                'rarete' => null,
            ],
        };
    }

    /**
     * Ids des modèles d'items dont le nom contient `$terme`, par famille.
     *
     * Ici pour la même raison que `decrireItem` : c'est le champ `nom` d'un côté et `name` de
     * l'autre, et ce savoir-là ne doit exister qu'une fois. Une famille sans résultat rend un
     * tableau vide, jamais une clé absente — l'appelant peut boucler sans tester.
     *
     * @return array<string, int[]> valeur de TypeItem => ids
     */
    public function rechercherItemsParNom(string $terme): array
    {
        return [
            TypeItem::EQUIPEMENT->value => $this->equipementRepository->findIdsParNom($terme),
            TypeItem::CONSOMMABLE->value => $this->consommableRepository->findIdsParNom($terme),
            TypeItem::OBJET->value => $this->objetRepository->findIdsParNom($terme),
        ];
    }

    public function trouverItem(TypeItem $type, int $itemId): Equipement|Consommable|Objet|null
    {
        return match ($type) {
            TypeItem::EQUIPEMENT => $this->equipementRepository->find($itemId),
            TypeItem::CONSOMMABLE => $this->consommableRepository->find($itemId),
            TypeItem::OBJET => $this->objetRepository->find($itemId),
        };
    }

    /* --------------------------------------------------------------- interne */

    private function getInventaire(User $user): Inventaire
    {
        $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
        if ($inventaire === null) {
            throw new \DomainException("Inventaire introuvable pour ce joueur.");
        }

        return $inventaire;
    }

    private function trouverLigne(
        Inventaire $inventaire,
        TypeItem $type,
        int $itemId
    ): InventaireEquipement|InventaireConsommable|InventaireObjet|null {
        return match ($type) {
            TypeItem::EQUIPEMENT => $this->inventaireEquipementRepository
                ->findOneBy(['inventaire' => $inventaire, 'equipement' => $itemId]),
            TypeItem::CONSOMMABLE => $this->inventaireConsommableRepository
                ->findOneBy(['inventaire' => $inventaire, 'consommable' => $itemId]),
            TypeItem::OBJET => $this->inventaireObjetRepository
                ->findOneBy(['inventaire' => $inventaire, 'objet' => $itemId]),
        };
    }
}
