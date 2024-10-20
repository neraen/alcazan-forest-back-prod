<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\MonstreObjetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=MonstreObjetRepository::class)
 */
#[ApiResource]
class MonstreObjet
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Monstre::class, inversedBy="monstreObjets")
     * @ORM\JoinColumn(nullable=false)
     */
    private $monstre;

    /**
     * @ORM\ManyToOne(targetEntity=Objet::class, inversedBy="objets")
     * @ORM\JoinColumn(nullable=false)
     */
    private $objet;

    /**
     * @ORM\Column(type="integer")
     */
    private $taux_drop;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $typeDrop;

    /**
     * @ORM\Column(type="integer")
     */
    private $diviseurTauxDrop;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonstre(): ?Monstre
    {
        return $this->monstre;
    }

    public function setMonstre(?Monstre $monstre): self
    {
        $this->monstre = $monstre;

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
        return $this->taux_drop;
    }

    public function setTauxDrop(int $taux_drop): self
    {
        $this->taux_drop = $taux_drop;

        return $this;
    }

    public function getTypeDrop(): ?string
    {
        return $this->typeDrop;
    }

    public function setTypeDrop(string $typeDrop): self
    {
        $this->typeDrop = $typeDrop;

        return $this;
    }

    public function getDiviseurTauxDrop(): ?int
    {
        return $this->diviseurTauxDrop;
    }

    public function setDiviseurTauxDrop(int $diviseurTauxDrop): self
    {
        $this->diviseurTauxDrop = $diviseurTauxDrop;

        return $this;
    }
}
