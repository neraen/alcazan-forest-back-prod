<?php

namespace App\Entity;

use App\Repository\BossRecompenseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BossRecompenseRepository::class)]
class BossRecompense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Boss::class, inversedBy: 'bossRecompenses')]
    private $boss;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Recompense::class, inversedBy: 'bossRecompenses')]
    private $recompense;

    #[ORM\Column(type: 'integer')]
    private $taux;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBoss(): ?Boss
    {
        return $this->boss;
    }

    public function setBoss(?Boss $boss): self
    {
        $this->boss = $boss;

        return $this;
    }

    public function getRecompense(): ?Recompense
    {
        return $this->recompense;
    }

    public function setRecompense(?Recompense $recompense): self
    {
        $this->recompense = $recompense;

        return $this;
    }

    public function getTaux(): ?int
    {
        return $this->taux;
    }

    public function setTaux(int $taux): self
    {
        $this->taux = $taux;

        return $this;
    }
}
