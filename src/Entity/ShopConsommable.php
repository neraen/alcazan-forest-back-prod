<?php

namespace App\Entity;

use App\Repository\ShopConsommableRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne « consommable » d'une boutique (section consommable), avec prix propre.
 * Le lien vers Consommable est unidirectionnel (pas d'inverse à maintenir).
 */
#[ORM\Entity(repositoryClass: ShopConsommableRepository::class)]
class ShopConsommable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Shop::class, inversedBy: 'shopConsommables')]
    private $shop;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Consommable::class)]
    private $consommable;

    /** Prix de vente en boutique (or). null = prix d'achat de base du consommable. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private $prix;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): ?Shop
    {
        return $this->shop;
    }

    public function setShop(?Shop $shop): self
    {
        $this->shop = $shop;

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

    public function getPrix(): ?int
    {
        return $this->prix;
    }

    public function setPrix(?int $prix): self
    {
        $this->prix = $prix;

        return $this;
    }
}
