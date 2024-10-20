<?php

namespace App\Entity;

use App\Repository\JoueurGuildeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=JoueurGuildeRepository::class)
 */
class JoueurGuilde
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=User::class, inversedBy="joueurGuildes")
     * @ORM\JoinColumn(nullable=false)
     */
    private $user;

    /**
     * @ORM\ManyToOne(targetEntity=Guilde::class, inversedBy="joueurGuildes")
     * @ORM\JoinColumn(nullable=false)
     */
    private $guilde;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $grade;

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

    public function getGuilde(): ?Guilde
    {
        return $this->guilde;
    }

    public function setGuilde(?Guilde $guilde): self
    {
        $this->guilde = $guilde;

        return $this;
    }

    public function getGrade(): ?string
    {
        return $this->grade;
    }

    public function setGrade(string $grade): self
    {
        $this->grade = $grade;

        return $this;
    }
}
