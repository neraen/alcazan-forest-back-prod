<?php

namespace App\Entity;

use App\Repository\RecompenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecompenseRepository::class)]
class Recompense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Sequence::class, inversedBy: 'recompenses')]
    private $sequence;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $money;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $experience;

    #[ORM\ManyToOne(targetEntity: Objet::class, inversedBy: 'recompenses')]
    private $objet;

    #[ORM\ManyToOne(targetEntity: Equipement::class, inversedBy: 'recompenses')]
    private $equipement;

    #[ORM\ManyToOne(targetEntity: Consommable::class, inversedBy: 'recompenses')]
    private $consommable;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $quantity;

    #[ORM\OneToMany(mappedBy: 'recompense', targetEntity: BossRecompense::class)]
    private $bossRecompenses;

    public function __construct()
    {
        $this->bossRecompenses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSequence(): ?Sequence
    {
        return $this->sequence;
    }

    public function setSequence(?Sequence $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getMoney(): ?int
    {
        return $this->money;
    }

    public function setMoney(?int $money): self
    {
        $this->money = $money;

        return $this;
    }

    public function getExperience(): ?int
    {
        return $this->experience;
    }

    public function setExperience(?int $experience): self
    {
        $this->experience = $experience;

        return $this;
    }

    public function getObjet(): ?Objet
    {
        return $this->objet;
    }

    public function setObjet(?Objet $objet): self
    {
        $this->objet = $objet;

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

    public function setQuantity(?int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * @return Collection|BossRecompense[]
     */
    public function getBossRecompenses(): Collection
    {
        return $this->bossRecompenses;
    }

    public function addBossRecompense(BossRecompense $bossRecompense): self
    {
        if (!$this->bossRecompenses->contains($bossRecompense)) {
            $this->bossRecompenses[] = $bossRecompense;
            $bossRecompense->setRecompense($this);
        }

        return $this;
    }

    public function removeBossRecompense(BossRecompense $bossRecompense): self
    {
        if ($this->bossRecompenses->removeElement($bossRecompense)) {
            // set the owning side to null (unless already changed)
            if ($bossRecompense->getRecompense() === $this) {
                $bossRecompense->setRecompense(null);
            }
        }

        return $this;
    }
}
