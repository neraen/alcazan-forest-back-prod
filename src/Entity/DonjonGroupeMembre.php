<?php

namespace App\Entity;

use App\Repository\DonjonGroupeMembreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inscription d'un joueur dans un groupe de donjon = RUNTIME (jamais exporté dans le seed).
 * Disparaît avec le groupe : l'appartenance qui compte une fois dans le donjon est
 * DonjonInstanceMembre.
 */
#[ORM\Entity(repositoryClass: DonjonGroupeMembreRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_donjon_groupe_membre', columns: ['groupe_id', 'user_id'])]
class DonjonGroupeMembre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DonjonGroupe::class, inversedBy: 'membres')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonGroupe $groupe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroupe(): ?DonjonGroupe
    {
        return $this->groupe;
    }

    public function setGroupe(?DonjonGroupe $groupe): self
    {
        $this->groupe = $groupe;

        return $this;
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

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
