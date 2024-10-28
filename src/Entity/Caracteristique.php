<?php

namespace App\Entity;

use App\Repository\CaracteristiqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CaracteristiqueRepository::class)]
class Caracteristique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $nom;

    #[ORM\OneToMany(mappedBy: 'caracteristique', targetEntity: JoueurCaracteristique::class)]
    private $joueurCaracteristiques;

    #[ORM\OneToMany(mappedBy: 'caracteristique', targetEntity: EquipementCaracteristique::class)]
    private $equipementCaracteristiques;

    #[ORM\OneToMany(mappedBy: 'Caracteristique', targetEntity: JoueurCaracteristiqueBonus::class)]
    private $joueurCaracteristiqueBonuses;

    #[ORM\OneToMany(mappedBy: 'Caracteristique', targetEntity: BuffCaracteristique::class)]
    private $buffCaracteristiques;

    public function __construct()
    {
        $this->joueurCaracteristiques = new ArrayCollection();
        $this->equipementCaracteristiques = new ArrayCollection();
        $this->joueurCaracteristiqueBonuses = new ArrayCollection();
        $this->buffCaracteristiques = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    /**
     * @return Collection|JoueurCaracteristique[]
     */
    public function getJoueurCaracteristiques(): Collection
    {
        return $this->joueurCaracteristiques;
    }

    public function addJoueurCaracteristique(JoueurCaracteristique $joueurCaracteristique): self
    {
        if (!$this->joueurCaracteristiques->contains($joueurCaracteristique)) {
            $this->joueurCaracteristiques[] = $joueurCaracteristique;
            $joueurCaracteristique->setCaracteristique($this);
        }

        return $this;
    }

    public function removeJoueurCaracteristique(JoueurCaracteristique $joueurCaracteristique): self
    {
        if ($this->joueurCaracteristiques->removeElement($joueurCaracteristique)) {
            // set the owning side to null (unless already changed)
            if ($joueurCaracteristique->getCaracteristique() === $this) {
                $joueurCaracteristique->setCaracteristique(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|EquipementCaracteristique[]
     */
    public function getEquipementCaracteristiques(): Collection
    {
        return $this->equipementCaracteristiques;
    }

    public function addEquipementCaracteristique(EquipementCaracteristique $equipementCaracteristique): self
    {
        if (!$this->equipementCaracteristiques->contains($equipementCaracteristique)) {
            $this->equipementCaracteristiques[] = $equipementCaracteristique;
            $equipementCaracteristique->setCaracteristique($this);
        }

        return $this;
    }

    public function removeEquipementCaracteristique(EquipementCaracteristique $equipementCaracteristique): self
    {
        if ($this->equipementCaracteristiques->removeElement($equipementCaracteristique)) {
            // set the owning side to null (unless already changed)
            if ($equipementCaracteristique->getCaracteristique() === $this) {
                $equipementCaracteristique->setCaracteristique(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|JoueurCaracteristiqueBonus[]
     */
    public function getJoueurCaracteristiqueBonuses(): Collection
    {
        return $this->joueurCaracteristiqueBonuses;
    }

    public function addJoueurCaracteristiqueBonus(JoueurCaracteristiqueBonus $joueurCaracteristiqueBonus): self
    {
        if (!$this->joueurCaracteristiqueBonuses->contains($joueurCaracteristiqueBonus)) {
            $this->joueurCaracteristiqueBonuses[] = $joueurCaracteristiqueBonus;
            $joueurCaracteristiqueBonus->setCaracteristique($this);
        }

        return $this;
    }

    public function removeJoueurCaracteristiqueBonus(JoueurCaracteristiqueBonus $joueurCaracteristiqueBonus): self
    {
        if ($this->joueurCaracteristiqueBonuses->removeElement($joueurCaracteristiqueBonus)) {
            // set the owning side to null (unless already changed)
            if ($joueurCaracteristiqueBonus->getCaracteristique() === $this) {
                $joueurCaracteristiqueBonus->setCaracteristique(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BuffCaracteristique[]
     */
    public function getBuffCaracteristiques(): Collection
    {
        return $this->buffCaracteristiques;
    }

    public function addBuffCaracteristique(BuffCaracteristique $buffCaracteristique): self
    {
        if (!$this->buffCaracteristiques->contains($buffCaracteristique)) {
            $this->buffCaracteristiques[] = $buffCaracteristique;
            $buffCaracteristique->setCaracteristique($this);
        }

        return $this;
    }

    public function removeBuffCaracteristique(BuffCaracteristique $buffCaracteristique): self
    {
        if ($this->buffCaracteristiques->removeElement($buffCaracteristique)) {
            // set the owning side to null (unless already changed)
            if ($buffCaracteristique->getCaracteristique() === $this) {
                $buffCaracteristique->setCaracteristique(null);
            }
        }

        return $this;
    }
}
