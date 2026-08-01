<?php

namespace App\Entity;

use App\Config\HotelVenteConfig;
use App\Enum\StatutHotelVente;
use App\Enum\TypeItem;
use App\Repository\HotelVenteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un lot déposé à l'hôtel des ventes par un joueur. Machine à états : HotelVenteService,
 * seul code autorisé à écrire dans cette table.
 *
 * SÉQUESTRE, PAS RÉSERVATION : l'objet mis en vente est sorti du sac (SacService::retirerItem)
 * et n'existe plus que dans cette ligne jusqu'à la vente, le retrait ou l'expiration. On
 * n'emploie volontairement PAS `reservation_ressource` — son seul usage (l'échange) dure cinq
 * minutes, alors qu'une annonce vit deux jours, et le joueur verrait dans son sac un objet
 * qu'il ne peut ni vendre, ni équiper, ni échanger sans comprendre pourquoi.
 *
 * Pas de colonne `version` contrairement à `Echange` : une annonce n'est pas co-éditée, seul
 * son statut peut basculer. La course entre deux acheteurs se règle par verrou pessimiste
 * plus test du statut, et le prix attendu envoyé par le client sert de garde d'écran périmé.
 *
 * Le lot est INDIVISIBLE : on achète la ligne entière. Autoriser l'achat partiel obligerait à
 * muter `quantite` sous concurrence et à gérer le reliquat ; vendre à l'unité se fait en
 * déposant plusieurs annonces, ce que borne ANNONCES_MAX_PAR_JOUEUR.
 */
#[ORM\Entity(repositoryClass: HotelVenteRepository::class)]
#[ORM\Index(name: 'idx_hotel_vente_catalogue', columns: ['statut', 'type', 'item_id'])]
#[ORM\Index(name: 'idx_hotel_vente_vendeur', columns: ['vendeur_id', 'statut'])]
#[ORM\Index(name: 'idx_hotel_vente_expiration', columns: ['statut', 'expires_at'])]
class HotelVente
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $vendeur;

    #[ORM\Column(type: 'string', length: 20, enumType: TypeItem::class)]
    private TypeItem $type;

    /**
     * Id du modèle d'item, SANS clé étrangère — même choix qu'`EchangeLigne` : le jeu n'a
     * pas d'instance d'objet (ni durabilité, ni enchantement), et `item_id` pointe vers l'une
     * des trois tables selon `type`. Corollaire : un item supprimé du contenu laisse une
     * annonce orpheline, que le normalizer doit savoir décrire sans planter.
     */
    #[ORM\Column(type: 'integer')]
    private int $itemId;

    #[ORM\Column(type: 'integer')]
    private int $quantite;

    /** Prix TOTAL du lot, en or. Le prix unitaire n'est qu'un affichage dérivé. */
    #[ORM\Column(type: 'integer')]
    private int $prix;

    /**
     * Frais réellement payés au dépôt. Figés ici plutôt que recalculés à l'affichage : le
     * taux est un curseur d'équilibrage, et le retoucher ne doit pas réécrire l'histoire de
     * ce qu'un joueur a déjà déboursé.
     */
    #[ORM\Column(type: 'integer')]
    private int $fraisDepot;

    #[ORM\Column(type: 'string', length: 20, enumType: StatutHotelVente::class)]
    private StatutHotelVente $statut = StatutHotelVente::EN_VENTE;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $acheteur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    /** Date de clôture, quelle qu'en soit la cause (vente, retrait, expiration). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(HotelVenteConfig::DUREE_VENTE);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVendeur(): User
    {
        return $this->vendeur;
    }

    public function setVendeur(User $vendeur): self
    {
        $this->vendeur = $vendeur;

        return $this;
    }

    public function getType(): TypeItem
    {
        return $this->type;
    }

    public function setType(TypeItem $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): self
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPrix(): int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): self
    {
        $this->prix = $prix;

        return $this;
    }

    /** Prix par exemplaire, arrondi au supérieur — affichage seul, jamais ce qui est débité. */
    public function getPrixUnitaire(): int
    {
        return $this->quantite > 0 ? (int) ceil($this->prix / $this->quantite) : $this->prix;
    }

    public function getFraisDepot(): int
    {
        return $this->fraisDepot;
    }

    public function setFraisDepot(int $fraisDepot): self
    {
        $this->fraisDepot = $fraisDepot;

        return $this;
    }

    public function getStatut(): StatutHotelVente
    {
        return $this->statut;
    }

    public function getAcheteur(): ?User
    {
        return $this->acheteur;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Réservé aux tests et aux corrections : la durée normale est posée à la construction. */
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function estOuverte(): bool
    {
        return $this->statut === StatutHotelVente::EN_VENTE;
    }

    public function estExpire(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    /**
     * Clôt l'annonce. L'objet séquestré doit avoir été transféré ou restitué par l'appelant
     * AVANT cet appel : une annonce close ne dit plus où est passé son contenu.
     */
    public function cloturer(StatutHotelVente $statut, ?User $acheteur = null): self
    {
        $this->statut = $statut;
        $this->acheteur = $acheteur;
        $this->closedAt = new \DateTimeImmutable();

        return $this;
    }
}
