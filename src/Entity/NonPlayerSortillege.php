<?php

namespace App\Entity;

use App\Repository\NonPlayerSortillegeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NonPlayerSortillegeRepository::class)]
class NonPlayerSortillege
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\Column(type: 'integer')]
    private $cooldown;

    #[ORM\Column(type: 'integer')]
    private $degatBase;

    #[ORM\Column(type: 'integer')]
    private $portee;

    #[ORM\Column(type: 'float')]
    private $coefPrincipal;

    #[ORM\Column(type: 'float')]
    private $coefSecondaire;

    #[ORM\Column(type: 'string', length: 255)]
    private $type;

    #[ORM\OneToMany(targetEntity: BossSortilege::class, mappedBy: 'nonPlayerSortilege')]
    private $bossSortileges;

    public function __construct()
    {
        $this->bossSortileges = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCooldown(): ?int
    {
        return $this->cooldown;
    }

    public function setCooldown(int $cooldown): self
    {
        $this->cooldown = $cooldown;

        return $this;
    }

    public function getDegatBase(): ?int
    {
        return $this->degatBase;
    }

    public function setDegatBase(int $degatBase): self
    {
        $this->degatBase = $degatBase;

        return $this;
    }

    public function getPortee(): ?int
    {
        return $this->portee;
    }

    public function setPortee(int $portee): self
    {
        $this->portee = $portee;

        return $this;
    }

    public function getCoefPrincipal(): ?int
    {
        return $this->coefPrincipal;
    }

    public function setCoefPrincipal(int $coefPrincipal): self
    {
        $this->coefPrincipal = $coefPrincipal;

        return $this;
    }

    public function getCoefSecondaire(): ?int
    {
        return $this->coefSecondaire;
    }

    public function setCoefSecondaire(int $coefSecondaire): self
    {
        $this->coefSecondaire = $coefSecondaire;

        return $this;
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

    /**
     * @return Collection|BossSortilege[]
     */
    public function getBossSortileges(): Collection
    {
        return $this->bossSortileges;
    }

    public function addBossSortilege(BossSortilege $bossSortilege): self
    {
        if (!$this->bossSortileges->contains($bossSortilege)) {
            $this->bossSortileges[] = $bossSortilege;
            $bossSortilege->setNonPlayerSortilege($this);
        }

        return $this;
    }

    public function removeBossSortilege(BossSortilege $bossSortilege): self
    {
        if ($this->bossSortileges->removeElement($bossSortilege)) {
            // set the owning side to null (unless already changed)
            if ($bossSortilege->getNonPlayerSortilege() === $this) {
                $bossSortilege->setNonPlayerSortilege(null);
            }
        }

        return $this;
    }
}
