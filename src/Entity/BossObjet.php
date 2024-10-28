<?php

namespace App\Entity;

use App\Repository\BossObjetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BossObjetRepository::class)]
class BossObjet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Boss::class, inversedBy: 'bossObjets')]
    private $boss;

    #[ORM\ManyToOne(targetEntity: Objet::class, inversedBy: 'bossObjets')]
    private $objet;

    #[ORM\Column(type: 'integer')]
    private $tauxDrop;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getObjet(): ?Objet
    {
        return $this->objet;
    }

    public function setObjet(?Objet $objet): self
    {
        $this->objet = $objet;

        return $this;
    }

    public function getTauxDrop(): ?int
    {
        return $this->tauxDrop;
    }

    public function setTauxDrop(int $tauxDrop): self
    {
        $this->tauxDrop = $tauxDrop;

        return $this;
    }
}
