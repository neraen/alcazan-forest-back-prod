<?php

namespace App\Entity;

use App\Repository\UserSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSequenceRepository::class)]
class UserSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userSequences')]
    private $user;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Sequence::class, inversedBy: 'userSequences')]
    private $Sequence;

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

    public function getSequence(): ?Sequence
    {
        return $this->Sequence;
    }

    public function setSequence(?Sequence $Sequence): self
    {
        $this->Sequence = $Sequence;

        return $this;
    }
}
