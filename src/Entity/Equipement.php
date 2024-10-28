<?php

namespace App\Entity;

use App\Repository\EquipementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementRepository::class)]
class Equipement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $nom;

    #[ORM\ManyToMany(targetEntity: Classe::class, inversedBy: 'equipements')]
    private $classe;

    #[ORM\Column(type: 'string', length: 255)]
    private $icone;

    #[ORM\Column(type: 'integer')]
    private $prixRevente;

    #[ORM\Column(type: 'integer')]
    private $prixAchat;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: InventaireEquipement::class)]
    private $inventaireEquipements;

    #[ORM\Column(type: 'integer')]
    private $level_min;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: EquipementCaracteristique::class)]
    private $equipementCaracteristiques;

    #[ORM\Column(type: 'text', nullable: true)]
    private $description;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: PositionEquipement::class, inversedBy: 'equipements')]
    private $positionEquipement;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: UserEquipement::class)]
    private $userEquipements;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Rarity::class, inversedBy: 'equipements')]
    private $rarity;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: ShopEquipement::class)]
    private $shopEquipements;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: Recompense::class)]
    private $recompenses;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: BossEquipement::class)]
    private $bossEquipements;

    #[ORM\OneToMany(mappedBy: 'equipement', targetEntity: Action::class)]
    private $actions;

    public function __construct()
    {
        $this->classe = new ArrayCollection();
        $this->inventaireEquipements = new ArrayCollection();
        $this->equipementCaracteristiques = new ArrayCollection();
        $this->userEquipements = new ArrayCollection();
        $this->shopEquipements = new ArrayCollection();
        $this->recompenses = new ArrayCollection();
        $this->bossEquipements = new ArrayCollection();
        $this->actions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    /**
     * @return Collection|Classe[]
     */
    public function getClasse(): Collection
    {
        return $this->classe;
    }

    public function addClasse(Classe $classe): self
    {
        if (!$this->classe->contains($classe)) {
            $this->classe[] = $classe;
        }

        return $this;
    }

    public function removeClasse(Classe $classe): self
    {
        $this->classe->removeElement($classe);

        return $this;
    }

    public function getIcone(): ?string
    {
        return $this->icone;
    }

    public function setIcone(string $icone): self
    {
        $this->icone = $icone;

        return $this;
    }

    public function getPrixRevente(): ?int
    {
        return $this->prixRevente;
    }

    public function setPrixRevente(int $prix_revente): self
    {
        $this->prixRevente = $prix_revente;

        return $this;
    }

    public function getPrixAchat(): ?int
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(int $prix_achat): self
    {
        $this->prixAchat = $prix_achat;

        return $this;
    }

    /**
     * @return Collection|InventaireEquipement[]
     */
    public function getInventaireEquipements(): Collection
    {
        return $this->inventaireEquipements;
    }

    public function addInventaireEquipement(InventaireEquipement $inventaireEquipement): self
    {
        if (!$this->inventaireEquipements->contains($inventaireEquipement)) {
            $this->inventaireEquipements[] = $inventaireEquipement;
            $inventaireEquipement->setEquipement($this);
        }

        return $this;
    }

    public function removeInventaireEquipement(InventaireEquipement $inventaireEquipement): self
    {
        if ($this->inventaireEquipements->removeElement($inventaireEquipement)) {
            // set the owning side to null (unless already changed)
            if ($inventaireEquipement->getEquipement() === $this) {
                $inventaireEquipement->setEquipement(null);
            }
        }

        return $this;
    }

    public function getLevelMin(): ?int
    {
        return $this->level_min;
    }

    public function setLevelMin(int $level_min): self
    {
        $this->level_min = $level_min;

        return $this;
    }

    /**
     * @return Collection|EquipementCaracteristique[]
     */
    public function getEquipementCaracteristiques(): Collection
    {
        return $this->equipementCaracteristiques;
    }

    public function addEquipementCaracteristique(EquipementCaracteristique $equipementCaracteristique): self
    {
        if (!$this->equipementCaracteristiques->contains($equipementCaracteristique)) {
            $this->equipementCaracteristiques[] = $equipementCaracteristique;
            $equipementCaracteristique->setEquipement($this);
        }

        return $this;
    }

    public function removeEquipementCaracteristique(EquipementCaracteristique $equipementCaracteristique): self
    {
        if ($this->equipementCaracteristiques->removeElement($equipementCaracteristique)) {
            // set the owning side to null (unless already changed)
            if ($equipementCaracteristique->getEquipement() === $this) {
                $equipementCaracteristique->setEquipement(null);
            }
        }

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

    public function getPositionEquipement(): ?PositionEquipement
    {
        return $this->positionEquipement;
    }

    public function setPositionEquipement(?PositionEquipement $positionEquipement): self
    {
        $this->positionEquipement = $positionEquipement;

        return $this;
    }

    /**
     * @return Collection|UserEquipement[]
     */
    public function getUserEquipements(): Collection
    {
        return $this->userEquipements;
    }

    public function addUserEquipement(UserEquipement $userEquipement): self
    {
        if (!$this->userEquipements->contains($userEquipement)) {
            $this->userEquipements[] = $userEquipement;
            $userEquipement->setEquipement($this);
        }

        return $this;
    }

    public function removeUserEquipement(UserEquipement $userEquipement): self
    {
        if ($this->userEquipements->removeElement($userEquipement)) {
            // set the owning side to null (unless already changed)
            if ($userEquipement->getEquipement() === $this) {
                $userEquipement->setEquipement(null);
            }
        }

        return $this;
    }

    public function getRarity(): ?Rarity
    {
        return $this->rarity;
    }

    public function setRarity(?Rarity $rarity): self
    {
        $this->rarity = $rarity;

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
            $shopEquipement->setEquipement($this);
        }

        return $this;
    }

    public function removeShopEquipement(ShopEquipement $shopEquipement): self
    {
        if ($this->shopEquipements->removeElement($shopEquipement)) {
            // set the owning side to null (unless already changed)
            if ($shopEquipement->getEquipement() === $this) {
                $shopEquipement->setEquipement(null);
            }
        }

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
            $recompense->setEquipement($this);
        }

        return $this;
    }

    public function removeRecompense(Recompense $recompense): self
    {
        if ($this->recompenses->removeElement($recompense)) {
            // set the owning side to null (unless already changed)
            if ($recompense->getEquipement() === $this) {
                $recompense->setEquipement(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BossEquipement[]
     */
    public function getBossEquipements(): Collection
    {
        return $this->bossEquipements;
    }

    public function addBossEquipement(BossEquipement $bossEquipement): self
    {
        if (!$this->bossEquipements->contains($bossEquipement)) {
            $this->bossEquipements[] = $bossEquipement;
            $bossEquipement->setEquipement($this);
        }

        return $this;
    }

    public function removeBossEquipement(BossEquipement $bossEquipement): self
    {
        if ($this->bossEquipements->removeElement($bossEquipement)) {
            // set the owning side to null (unless already changed)
            if ($bossEquipement->getEquipement() === $this) {
                $bossEquipement->setEquipement(null);
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
            $action->setEquipement($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getEquipement() === $this) {
                $action->setEquipement(null);
            }
        }

        return $this;
    }
}
