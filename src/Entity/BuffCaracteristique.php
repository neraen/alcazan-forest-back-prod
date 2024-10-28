<?php

namespace App\Entity;

use App\Repository\BuffCaracteristiqueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BuffCaracteristiqueRepository::class)]
class BuffCaracteristique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Buff::class, inversedBy: 'buffCaracteristiques')]
    private $buff;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Caracteristique::class, inversedBy: 'buffCaracteristiques')]
    private $caracteristique;

    #[ORM\Column(type: 'integer')]
    private $value;

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

    public function getCaracteristique(): ?Caracteristique
    {
        return $this->Caracteristique;
    }

    public function setCaracteristique(?Caracteristique $Caracteristique): self
    {
        $this->Caracteristique = $Caracteristique;

        return $this;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    public function setValue(int $value): self
    {
        $this->value = $value;

        return $this;
    }
}
