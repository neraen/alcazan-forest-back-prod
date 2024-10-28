<?php

namespace App\Entity;

use App\Repository\UserBossRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserBossRepository::class)]
class UserBoss
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userBosses')]
    private $user;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Boss::class, inversedBy: 'userBosses')]
    private $boss;

    #[ORM\Column(type: 'datetime')]
    private $lastKill;

    #[ORM\Column(type: 'integer')]
    private $numberKill;

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

    public function getBoss(): ?Boss
    {
        return $this->boss;
    }

    public function setBoss(?Boss $boss): self
    {
        $this->boss = $boss;

        return $this;
    }

    public function getLastKill(): ?\DateTimeInterface
    {
        return $this->lastKill;
    }

    public function setLastKill(\DateTimeInterface $lastKill): self
    {
        $this->lastKill = $lastKill;

        return $this;
    }

    public function getNumberKill(): ?int
    {
        return $this->numberKill;
    }

    public function setNumberKill(int $numberKill): self
    {
        $this->numberKill = $numberKill;

        return $this;
    }
}
