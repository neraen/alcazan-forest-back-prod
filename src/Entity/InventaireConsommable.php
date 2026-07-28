<?php

namespace App\Entity;

use App\Repository\InventaireConsommableRepository;
use Doctrine\ORM\Mapping as ORM;

// Une seule ligne de pile par couple sac/objet : garde-fou en base contre les doublons
// d'inventaire (cf. EquipementEquipeService).
#[ORM\Entity(repositoryClass: InventaireConsommableRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_inventaire_consommable', columns: ['inventaire_id', 'consommable_id'])]
class InventaireConsommable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Inventaire::class, inversedBy: 'inventaireConsommables')]
    private $inventaire;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Consommable::class, inversedBy: 'no')]
    private $consommable;

    #[ORM\Column(type: 'integer')]
    private $quantity;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInventaire(): ?Inventaire
    {
        return $this->inventaire;
    }

    public function setInventaire(?Inventaire $inventaire): self
    {
        $this->inventaire = $inventaire;

        return $this;
    }

    public function getConsommable(): ?Consommable
    {
        return $this->consommable;
    }

    public function setConsommable(?Consommable $consommable): self
    {
        $this->consommable = $consommable;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }
}
