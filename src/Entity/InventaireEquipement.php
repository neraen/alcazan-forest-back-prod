<?php

namespace App\Entity;

use App\Repository\InventaireEquipementRepository;
use Doctrine\ORM\Mapping as ORM;

// Une seule ligne de pile par couple sac/objet : garde-fou en base contre les doublons
// d'inventaire (cf. EquipementEquipeService).
#[ORM\Entity(repositoryClass: InventaireEquipementRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_inventaire_equipement', columns: ['inventaire_id', 'equipement_id'])]
class InventaireEquipement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Inventaire::class, inversedBy: 'inventaireEquipements')]
    private $inventaire;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Equipement::class, inversedBy: 'inventaireEquipements')]
    private $equipement;

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

    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;

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
