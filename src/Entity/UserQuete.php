<?php

namespace App\Entity;

use App\Repository\UserQueteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserQueteRepository::class)]
class UserQuete
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userQuetes')]
    private $user;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Quete::class, inversedBy: 'userQuetes')]
    private $quete;

    #[ORM\Column(type: 'boolean')]
    private $isDone;

    #[ORM\ManyToOne(targetEntity: Sequence::class, inversedBy: 'userQuetes')]
    private $sequence;

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

    public function getQuete(): ?Quete
    {
        return $this->quete;
    }

    public function setQuete(?Quete $quete): self
    {
        $this->quete = $quete;

        return $this;
    }

    public function getIsDone(): ?bool
    {
        return $this->isDone;
    }

    public function setIsDone(bool $isDone): self
    {
        $this->isDone = $isDone;

        return $this;
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
}
