<?php

namespace App\Entity;

use App\Repository\CarreauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CarreauRepository::class)]
class Carreau
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $type;

    #[ORM\Column(type: 'boolean')]
    private $isWrap;


    #[ORM\Column(type: 'boolean')]
    private $isUsable;

    #[ORM\OneToMany(mappedBy: 'carreau', targetEntity: CarteCarreau::class)]
    private $carteCarreaux;


    public function __construct()
    {
        $this->carteCarreaux = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }


    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getIsWrap(): ?bool
    {
        return $this->isWrap;
    }

    public function setIsWrap(bool $is_wrap): self
    {
        $this->isWrap = $is_wrap;

        return $this;
    }

    public function getIsUsable(): ?bool
    {
        return $this->isUsable;
    }

    public function setIsUsable(bool $is_usable): self
    {
        $this->isUsable = $is_usable;

        return $this;
    }

    /**
     * @return Collection|CarteCarreau[]
     */
    public function getCarteCarreaux(): Collection
    {
        return $this->carteCarreaux;
    }

    public function addCarteCarreaux(CarteCarreau $carteCarreaux): self
    {
        if (!$this->carteCarreaux->contains($carteCarreaux)) {
            $this->carteCarreaux[] = $carteCarreaux;
            $carteCarreaux->setCarreau($this);
        }

        return $this;
    }

    public function removeCarteCarreaux(CarteCarreau $carteCarreaux): self
    {
        if ($this->carteCarreaux->removeElement($carteCarreaux)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreaux->getCarreau() === $this) {
                $carteCarreaux->setCarreau(null);
            }
        }

        return $this;
    }
}
