<?php

namespace App\Entity;

use App\Repository\InventaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventaireRepository::class)]
class Inventaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\OneToOne(targetEntity: User::class, cascade: ['persist', 'remove'])]
    private $user;

    #[ORM\Column(type: 'integer')]
    private $tailleMax;

    #[ORM\OneToMany(mappedBy: 'inventaire', targetEntity: InventaireObjet::class)]
    private $inventaireObjets;

    #[ORM\OneToMany(mappedBy: 'inventaire', targetEntity: InventaireEquipement::class)]
    private $inventaireEquipements;

    #[ORM\OneToMany(mappedBy: 'inventaire', targetEntity: InventaireConsommable::class)]
    private $inventaireConsommables;

    public function __construct()
    {
        $this->inventaireObjets = new ArrayCollection();
        $this->inventaireEquipements = new ArrayCollection();
        $this->inventaireConsommables = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getTailleMax(): ?int
    {
        return $this->tailleMax;
    }

    public function setTailleMax(int $taille_max): self
    {
        $this->tailleMax = $taille_max;

        return $this;
    }

    /**
     * @return Collection|InventaireObjet[]
     */
    public function getInventaireObjets(): Collection
    {
        return $this->inventaireObjets;
    }

    public function addInventaireObjet(InventaireObjet $inventaireObjet): self
    {
        if (!$this->inventaireObjets->contains($inventaireObjet)) {
            $this->inventaireObjets[] = $inventaireObjet;
            $inventaireObjet->setInventaire($this);
        }

        return $this;
    }

    public function removeInventaireObjet(InventaireObjet $inventaireObjet): self
    {
        if ($this->inventaireObjets->removeElement($inventaireObjet)) {
            // set the owning side to null (unless already changed)
            if ($inventaireObjet->getInventaire() === $this) {
                $inventaireObjet->setInventaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|InventaireEquipement[]
     */
    public function getInventaireEquipements(): Collection
    {
        return $this->inventaireEquipements;
    }

    public function addInventaireEquipement(InventaireEquipement $inventaireEquipement): self
    {
        if (!$this->inventaireEquipements->contains($inventaireEquipement)) {
            $this->inventaireEquipements[] = $inventaireEquipement;
            $inventaireEquipement->setInventaire($this);
        }

        return $this;
    }

    public function removeInventaireEquipement(InventaireEquipement $inventaireEquipement): self
    {
        if ($this->inventaireEquipements->removeElement($inventaireEquipement)) {
            // set the owning side to null (unless already changed)
            if ($inventaireEquipement->getInventaire() === $this) {
                $inventaireEquipement->setInventaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|InventaireConsommable[]
     */
    public function getInventaireConsommables(): Collection
    {
        return $this->inventaireConsommables;
    }

    public function addInventaireConsommable(InventaireConsommable $inventaireConsommable): self
    {
        if (!$this->inventaireConsommables->contains($inventaireConsommable)) {
            $this->inventaireConsommables[] = $inventaireConsommable;
            $inventaireConsommable->setInventaire($this);
        }

        return $this;
    }

    public function removeInventaireConsommable(InventaireConsommable $inventaireConsommable): self
    {
        if ($this->inventaireConsommables->removeElement($inventaireConsommable)) {
            // set the owning side to null (unless already changed)
            if ($inventaireConsommable->getInventaire() === $this) {
                $inventaireConsommable->setInventaire(null);
            }
        }

        return $this;
    }
}
