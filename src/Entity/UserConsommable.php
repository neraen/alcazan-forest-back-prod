<?php

namespace App\Entity;

use App\Repository\UserConsommableRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserConsommableRepository::class)]
class UserConsommable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userConsommables')]
    private $user;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Consommable::class, inversedBy: 'userConsommables')]
    private $consommable;

    #[ORM\Column(type: 'integer')]
    private $position;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $quantity;

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

    public function getConsommable(): ?Consommable
    {
        return $this->consommable;
    }

    public function setConsommable(?Consommable $consommable): self
    {
        $this->consommable = $consommable;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

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
}
