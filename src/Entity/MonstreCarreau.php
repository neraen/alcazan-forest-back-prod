<?php

namespace App\Entity;

use App\Repository\MonstreCarreauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonstreCarreauRepository::class)]
class MonstreCarreau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Monstre::class, inversedBy: 'case')]
    private $monstre;

    #[ORM\Column(type: 'integer')]
    private $quantity;

    #[ORM\Column(type: 'integer')]
    private $current_life;

    #[ORM\Column(type: 'integer')]
    private $quantity_base;

    #[ORM\OneToMany(mappedBy: 'monstreCarreau', targetEntity: CarteCarreau::class)]
    private $carteCarreaus;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: CarteCarreau::class, inversedBy: 'monstreCarreaus')]
    private $carteCarreau;

    public function __construct()
    {
        $this->carteCarreaus = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonstre(): ?Monstre
    {
        return $this->monstre;
    }

    public function setMonstre(?Monstre $monstre): self
    {
        $this->monstre = $monstre;

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

    public function getCurrentLife(): ?int
    {
        return $this->current_life;
    }

    public function setCurrentLife(int $current_life): self
    {
        $this->current_life = $current_life;

        return $this;
    }

    public function getQuantityBase(): ?int
    {
        return $this->quantity_base;
    }

    public function setQuantityBase(int $quantity_base): self
    {
        $this->quantity_base = $quantity_base;

        return $this;
    }

    /**
     * @return Collection|CarteCarreau[]
     */
    public function getCarteCarreaus(): Collection
    {
        return $this->carteCarreaus;
    }

    public function addCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if (!$this->carteCarreaus->contains($carteCarreau)) {
            $this->carteCarreaus[] = $carteCarreau;
            $carteCarreau->setMonstreCarreau($this);
        }

        return $this;
    }

    public function removeCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if ($this->carteCarreaus->removeElement($carteCarreau)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreau->getMonstreCarreau() === $this) {
                $carteCarreau->setMonstreCarreau(null);
            }
        }

        return $this;
    }

    public function getCarteCarreau(): ?CarteCarreau
    {
        return $this->carteCarreau;
    }

    public function setCarteCarreau(?CarteCarreau $carteCarreau): self
    {
        $this->carteCarreau = $carteCarreau;

        return $this;
    }
}
