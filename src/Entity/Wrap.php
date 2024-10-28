<?php

namespace App\Entity;

use App\Repository\WrapRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WrapRepository::class)]
class Wrap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $value;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $mapCondition;

    #[ORM\OneToMany(mappedBy: 'wrap', targetEntity: CarteCarreau::class)]
    private $carteCarreaus;

    public function __construct()
    {
        $this->carteCarreaus = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    public function setValue(?int $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getMapCondition(): ?string
    {
        return $this->mapCondition;
    }

    public function setMapCondition(?string $mapCondition): self
    {
        $this->mapCondition = $mapCondition;

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
            $carteCarreau->setWrap($this);
        }

        return $this;
    }

    public function removeCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if ($this->carteCarreaus->removeElement($carteCarreau)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreau->getWrap() === $this) {
                $carteCarreau->setWrap(null);
            }
        }

        return $this;
    }
}
