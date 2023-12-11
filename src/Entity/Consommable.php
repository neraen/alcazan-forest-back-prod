<?php

namespace App\Entity;

use App\Repository\ConsommableRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ConsommableRepository::class)
 */
class Consommable
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
    private $nom;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $icone;

    /**
     * @ORM\OneToMany(targetEntity=InventaireConsommable::class, mappedBy="inventaire")
     */
    private $inventaireConsommables;

    /**
     * @ORM\OneToMany(targetEntity=InventaireConsommable::class, mappedBy="consommable")
     */
    private $no;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $description;

    /**
     * @ORM\Column(type="integer")
     */
    private $prixRevente;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $PrixAchat;

    /**
     * @ORM\OneToMany(targetEntity=Recompense::class, mappedBy="consommable")
     */
    private $recompenses;

    /**
     * @ORM\Column(type="float")
     */
    private $cooldown;

    /**
     * @ORM\Column(type="integer")
     */
    private $points;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $isBuff;

    /**
     * @ORM\OneToMany(targetEntity=UserConsommable::class, mappedBy="consommable")
     */
    private $userConsommables;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $type;

    /**
     * @ORM\OneToMany(targetEntity=Action::class, mappedBy="consommable")
     */
    private $actions;

    public function __construct()
    {
        $this->inventaireConsommables = new ArrayCollection();
        $this->no = new ArrayCollection();
        $this->recompenses = new ArrayCollection();
        $this->userConsommables = new ArrayCollection();
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

    public function getIcone(): ?string
    {
        return $this->icone;
    }

    public function setIcone(string $icone): self
    {
        $this->icone = $icone;

        return $this;
    }

    /**
     * @return Collection|InventaireConsommable[]
     */
    public function getInventaireConsommables(): Collection
    {
        return $this->inventaireConsommables;
    }

    public function addInventaireConsommable(InventaireConsommable $inventaireConsommable): self
    {
        if (!$this->inventaireConsommables->contains($inventaireConsommable)) {
            $this->inventaireConsommables[] = $inventaireConsommable;
            $inventaireConsommable->setInventaire($this);
        }

        return $this;
    }

    public function removeInventaireConsommable(InventaireConsommable $inventaireConsommable): self
    {
        if ($this->inventaireConsommables->removeElement($inventaireConsommable)) {
            // set the owning side to null (unless already changed)
            if ($inventaireConsommable->getInventaire() === $this) {
                $inventaireConsommable->setInventaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|InventaireConsommable[]
     */
    public function getNo(): Collection
    {
        return $this->no;
    }

    public function addNo(InventaireConsommable $no): self
    {
        if (!$this->no->contains($no)) {
            $this->no[] = $no;
            $no->setConsommable($this);
        }

        return $this;
    }

    public function removeNo(InventaireConsommable $no): self
    {
        if ($this->no->removeElement($no)) {
            // set the owning side to null (unless already changed)
            if ($no->getConsommable() === $this) {
                $no->setConsommable(null);
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

    public function getPrixRevente(): ?int
    {
        return $this->prixRevente;
    }

    public function setPrixRevente(int $prixRevente): self
    {
        $this->prixRevente = $prixRevente;

        return $this;
    }

    public function getPrixAchat(): ?int
    {
        return $this->PrixAchat;
    }

    public function setPrixAchat(?int $PrixAchat): self
    {
        $this->PrixAchat = $PrixAchat;

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
            $recompense->setConsommable($this);
        }

        return $this;
    }

    public function removeRecompense(Recompense $recompense): self
    {
        if ($this->recompenses->removeElement($recompense)) {
            // set the owning side to null (unless already changed)
            if ($recompense->getConsommable() === $this) {
                $recompense->setConsommable(null);
            }
        }

        return $this;
    }

    public function getCooldown(): ?float
    {
        return $this->cooldown;
    }

    public function setCooldown(float $cooldown): self
    {
        $this->cooldown = $cooldown;

        return $this;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function setPoints(int $points): self
    {
        $this->points = $points;

        return $this;
    }

    public function getIsBuff(): ?bool
    {
        return $this->isBuff;
    }

    public function setIsBuff(?bool $isBuff): self
    {
        $this->isBuff = $isBuff;

        return $this;
    }

    /**
     * @return Collection|UserConsommable[]
     */
    public function getUserConsommables(): Collection
    {
        return $this->userConsommables;
    }

    public function addUserConsommable(UserConsommable $userConsommable): self
    {
        if (!$this->userConsommables->contains($userConsommable)) {
            $this->userConsommables[] = $userConsommable;
            $userConsommable->setConsommable($this);
        }

        return $this;
    }

    public function removeUserConsommable(UserConsommable $userConsommable): self
    {
        if ($this->userConsommables->removeElement($userConsommable)) {
            // set the owning side to null (unless already changed)
            if ($userConsommable->getConsommable() === $this) {
                $userConsommable->setConsommable(null);
            }
        }

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

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
            $action->setConsommable($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getConsommable() === $this) {
                $action->setConsommable(null);
            }
        }

        return $this;
    }
}
