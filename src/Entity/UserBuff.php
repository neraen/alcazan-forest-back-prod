<?php

namespace App\Entity;

use App\Repository\UserBuffRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=UserBuffRepository::class)
 */
class UserBuff
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Buff::class, inversedBy="userBuffs")
     */
    private $buff;

    /**
     * @ORM\ManyToOne(targetEntity=User::class, inversedBy="userBuffs")
     * @ORM\JoinColumn(nullable=false)
     */
    private $user;

    /**
     * @ORM\Column(type="datetime")
     */
    private $dateDebut;

    /**
     * @ORM\Column(type="datetime")
     */
    private $dateFin;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuff(): ?Buff
    {
        return $this->buff;
    }

    public function setBuff(?Buff $buff): self
    {
        $this->buff = $buff;

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

    public function getDateDebut(): ?\DateTimeInterface
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeInterface $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeInterface
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeInterface $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }
}
