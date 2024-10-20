<?php

namespace App\Entity;

use App\Repository\ShopRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ShopRepository::class)
 */
class Shop
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $name;

    /**
     * @ORM\OneToMany(targetEntity=ShopObjet::class, mappedBy="shop")
     */
    private $shopObjets;

    /**
     * @ORM\Column(type="string", length=30)
     */
    private $type;

    /**
     * @ORM\OneToMany(targetEntity=Pnj::class, mappedBy="shop")
     */
    private $pnjs;

    /**
     * @ORM\OneToMany(targetEntity=ShopEquipement::class, mappedBy="shop")
     */
    private $shopEquipements;

    public function __construct()
    {
        $this->shopObjets = new ArrayCollection();
        $this->pnjs = new ArrayCollection();
        $this->shopEquipements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|ShopObjet[]
     */
    public function getShopObjets(): Collection
    {
        return $this->shopObjets;
    }

    public function addShopObjet(ShopObjet $shopObjet): self
    {
        if (!$this->shopObjets->contains($shopObjet)) {
            $this->shopObjets[] = $shopObjet;
            $shopObjet->setShop($this);
        }

        return $this;
    }

    public function removeShopObjet(ShopObjet $shopObjet): self
    {
        if ($this->shopObjets->removeElement($shopObjet)) {
            // set the owning side to null (unless already changed)
            if ($shopObjet->getShop() === $this) {
                $shopObjet->setShop(null);
            }
        }

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return Collection|Pnj[]
     */
    public function getPnjs(): Collection
    {
        return $this->pnjs;
    }

    public function addPnj(Pnj $pnj): self
    {
        if (!$this->pnjs->contains($pnj)) {
            $this->pnjs[] = $pnj;
            $pnj->setShop($this);
        }

        return $this;
    }

    public function removePnj(Pnj $pnj): self
    {
        if ($this->pnjs->removeElement($pnj)) {
            // set the owning side to null (unless already changed)
            if ($pnj->getShop() === $this) {
                $pnj->setShop(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|ShopEquipement[]
     */
    public function getShopEquipements(): Collection
    {
        return $this->shopEquipements;
    }

    public function addShopEquipement(ShopEquipement $shopEquipement): self
    {
        if (!$this->shopEquipements->contains($shopEquipement)) {
            $this->shopEquipements[] = $shopEquipement;
            $shopEquipement->setShop($this);
        }

        return $this;
    }

    public function removeShopEquipement(ShopEquipement $shopEquipement): self
    {
        if ($this->shopEquipements->removeElement($shopEquipement)) {
            // set the owning side to null (unless already changed)
            if ($shopEquipement->getShop() === $this) {
                $shopEquipement->setShop(null);
            }
        }

        return $this;
    }
}
