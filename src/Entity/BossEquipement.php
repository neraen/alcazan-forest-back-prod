<?php

namespace App\Entity;

use App\Repository\BossEquipementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BossEquipementRepository::class)
 */
class BossEquipement
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Boss::class, inversedBy="bossEquipements")
     */
    private $boss;

    /**
     * @ORM\ManyToOne(targetEntity=Equipement::class, inversedBy="bossEquipements")
     */
    private $equipement;

    /**
     * @ORM\Column(type="integer")
     */
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

    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;

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
