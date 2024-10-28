<?php

namespace App\Entity;

use App\Repository\NiveauJoueurRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NiveauJoueurRepository::class)]
class NiveauJoueur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\OneToOne(inversedBy: 'niveauJoueur', targetEntity: User::class, cascade: ['persist', 'remove'])]
    private $user;


    #[ORM\ManyToOne(targetEntity: Niveau::class, inversedBy: 'niveauJoueurs')]
    private $niveau;


    #[ORM\Column(type: 'integer')]
    private $experience;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getNiveau(): ?Niveau
    {
        return $this->niveau;
    }

    public function setNiveau(?Niveau $niveau): self
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getExperience(): ?int
    {
        return $this->experience;
    }

    public function setExperience(int $experience): self
    {
        $this->experience = $experience;

        return $this;
    }
}
