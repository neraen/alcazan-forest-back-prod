<?php

namespace App\Entity;


use App\Repository\ObjetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ObjetRepository::class)]
class Objet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\Column(type: 'string', length: 2000, nullable: true)]
    private $description;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $prix_vente;

    #[ORM\OneToMany(mappedBy: 'objet', targetEntity: MonstreObjet::class)]
    private $objets;

    #[ORM\OneToMany(mappedBy: 'objet', targetEntity: ShopObjet::class)]
    private $shopObjets;

    #[ORM\OneToMany(mappedBy: 'objet', targetEntity: InventaireObjet::class)]
    private $inventaireObjets;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $image;

    #[ORM\OneToMany(mappedBy: 'objet', targetEntity: Recompense::class)]
    private $recompenses;

    #[ORM\OneToMany(mappedBy: 'objet', targetEntity: BossObjet::class)]
    private $bossObjets;

    #[ORM\OneToMany(mappedBy: 'objet', targetEntity: Action::class)]
    private $actions;

    public function __construct()
    {
        $this->objets = new ArrayCollection();
        $this->shopObjets = new ArrayCollection();
        $this->inventaireObjets = new ArrayCollection();
        $this->recompenses = new ArrayCollection();
        $this->bossObjets = new ArrayCollection();
        $this->actions = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPrixVente(): ?int
    {
        return $this->prix_vente;
    }

    public function setPrixVente(?int $prix_vente): self
    {
        $this->prix_vente = $prix_vente;

        return $this;
    }

    /**
     * @return Collection|MonstreObjet[]
     */
    public function getObjets(): Collection
    {
        return $this->objets;
    }

    public function addObjet(MonstreObjet $objet): self
    {
        if (!$this->objets->contains($objet)) {
            $this->objets[] = $objet;
            $objet->setObjet($this);
        }

        return $this;
    }

    public function removeObjet(MonstreObjet $objet): self
    {
        if ($this->objets->removeElement($objet)) {
            // set the owning side to null (unless already changed)
            if ($objet->getObjet() === $this) {
                $objet->setObjet(null);
            }
        }

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
            $shopObjet->setObjet($this);
        }

        return $this;
    }

    public function removeShopObjet(ShopObjet $shopObjet): self
    {
        if ($this->shopObjets->removeElement($shopObjet)) {
            // set the owning side to null (unless already changed)
            if ($shopObjet->getObjet() === $this) {
                $shopObjet->setObjet(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|InventaireObjet[]
     */
    public function getInventaireObjets(): Collection
    {
        return $this->inventaireObjets;
    }

    public function addInventaireObjet(InventaireObjet $inventaireObjet): self
    {
        if (!$this->inventaireObjets->contains($inventaireObjet)) {
            $this->inventaireObjets[] = $inventaireObjet;
            $inventaireObjet->setObjet($this);
        }

        return $this;
    }

    public function removeInventaireObjet(InventaireObjet $inventaireObjet): self
    {
        if ($this->inventaireObjets->removeElement($inventaireObjet)) {
            // set the owning side to null (unless already changed)
            if ($inventaireObjet->getObjet() === $this) {
                $inventaireObjet->setObjet(null);
            }
        }

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @return Collection|Recompense[]
     */
    public function getRecompenses(): Collection
    {
        return $this->recompenses;
    }

    public function addRecompense(Recompense $recompense): self
    {
        if (!$this->recompenses->contains($recompense)) {
            $this->recompenses[] = $recompense;
            $recompense->setObjet($this);
        }

        return $this;
    }

    public function removeRecompense(Recompense $recompense): self
    {
        if ($this->recompenses->removeElement($recompense)) {
            // set the owning side to null (unless already changed)
            if ($recompense->getObjet() === $this) {
                $recompense->setObjet(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BossObjet[]
     */
    public function getBossObjets(): Collection
    {
        return $this->bossObjets;
    }

    public function addBossObjet(BossObjet $bossObjet): self
    {
        if (!$this->bossObjets->contains($bossObjet)) {
            $this->bossObjets[] = $bossObjet;
            $bossObjet->setObjet($this);
        }

        return $this;
    }

    public function removeBossObjet(BossObjet $bossObjet): self
    {
        if ($this->bossObjets->removeElement($bossObjet)) {
            // set the owning side to null (unless already changed)
            if ($bossObjet->getObjet() === $this) {
                $bossObjet->setObjet(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Action>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(Action $action): self
    {
        if (!$this->actions->contains($action)) {
            $this->actions[] = $action;
            $action->setObjet($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getObjet() === $this) {
                $action->setObjet(null);
            }
        }

        return $this;
    }
}
