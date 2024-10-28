<?php

namespace App\Entity;

use App\Repository\JoueurCaracteristiqueBonusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JoueurCaracteristiqueBonusRepository::class)]
class JoueurCaracteristiqueBonus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'joueurCaracteristiqueBonuses')]
    private $joueur;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Caracteristique::class, inversedBy: 'joueurCaracteristiqueBonuses')]
    private $caracteristique;

    #[ORM\Column(type: 'integer')]
    private $points;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): ?User
    {
        return $this->joueur;
    }

    public function setJoueur(?User $joueur): self
    {
        $this->joueur = $joueur;

        return $this;
    }

    public function getCaracteristique(): ?Caracteristique
    {
        return $this->caracteristique;
    }

    public function setCaracteristique(?Caracteristique $caracteristique): self
    {
        $this->caracteristique = $caracteristique;

        return $this;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function setPoints(int $points): self
    {
        $this->points = $points;

        return $this;
    }
}
