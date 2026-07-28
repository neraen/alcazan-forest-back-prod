<?php

namespace App\Entity;

use App\Enum\TypeRessource;
use App\Repository\ReservationRessourceRepository;
use Doctrine\ORM\Mapping as ORM;

// Quantité d'item ou d'or mise de côté pour une opération en cours (échange, etc.). Le disponible
// d'un joueur = possédé - somme de ses réservations ; seul SacService lit et écrit cette table.
#[ORM\Entity(repositoryClass: ReservationRessourceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_reservation_ressource', columns: ['user_id', 'type', 'item_id', 'origine', 'origine_id'])]
#[ORM\Index(name: 'idx_reservation_user_ressource', columns: ['user_id', 'type', 'item_id'])]
class ReservationRessource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'string', length: 20, enumType: TypeRessource::class)]
    private TypeRessource $type;

    // 0 pour l'or : la ressource n'est pas un item.
    #[ORM\Column(type: 'integer')]
    private int $itemId = 0;

    #[ORM\Column(type: 'integer')]
    private int $quantite;

    // Fonctionnalité à l'origine de la réservation ('echange', ...) + id de la session concernée.
    #[ORM\Column(type: 'string', length: 32)]
    private string $origine;

    #[ORM\Column(type: 'integer')]
    private int $origineId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getType(): TypeRessource
    {
        return $this->type;
    }

    public function setType(TypeRessource $type): self
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

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): self
    {
        $this->origine = $origine;

        return $this;
    }

    public function getOrigineId(): int
    {
        return $this->origineId;
    }

    public function setOrigineId(int $origineId): self
    {
        $this->origineId = $origineId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
