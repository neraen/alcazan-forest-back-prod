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

    /**
     * Dernier ramassage du butin du boss. Le coffre n'est lootable qu'une fois
     * par mise à mort : `lastLoot` null ou antérieur à `lastKill` = butin disponible.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastLoot = null;

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

    public function getLastLoot(): ?\DateTimeInterface
    {
        return $this->lastLoot;
    }

    public function setLastLoot(?\DateTimeInterface $lastLoot): self
    {
        $this->lastLoot = $lastLoot;

        return $this;
    }

    /** Le butin de la dernière mise à mort n'a pas encore été ramassé. */
    public function butinDisponible(): bool
    {
        return $this->lastKill !== null
            && ($this->lastLoot === null || $this->lastLoot < $this->lastKill);
    }
}
