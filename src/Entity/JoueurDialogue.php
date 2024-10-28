<?php

namespace App\Entity;

use App\Repository\JoueurDialogueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JoueurDialogueRepository::class)]
class JoueurDialogue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'joueurDialogues')]
    private $joueur;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Dialogue::class, inversedBy: 'joueurDialogues')]
    private $dialogue;

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

    public function getDialogue(): ?Dialogue
    {
        return $this->dialogue;
    }

    public function setDialogue(?Dialogue $dialogue): self
    {
        $this->dialogue = $dialogue;

        return $this;
    }
}
