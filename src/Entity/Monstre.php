<?php

namespace App\Entity;

use App\Repository\MonstreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonstreRepository::class)]
class Monstre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\Column(type: 'integer')]
    private $maxLife;

    #[ORM\OneToOne(inversedBy: 'monstres', targetEntity: CarteCarreau::class, cascade: ['persist', 'remove'])]
    private $carteCarreau;

    #[ORM\Column(type: 'integer')]
    private $temps_repop;

    #[ORM\OneToMany(mappedBy: 'monstre', targetEntity: MonstreCarreau::class)]
    private $case;

    #[ORM\OneToMany(mappedBy: 'monstre', targetEntity: MonstreObjet::class)]
    private $monstreObjets;

    #[ORM\Column(type: 'string', length: 255)]
    private $skin;

    #[ORM\Column(type: 'integer')]
    private $puissance;

    #[ORM\OneToMany(mappedBy: 'monstre', targetEntity: Action::class)]
    private $actions;

    public function __construct()
    {
        $this->quantite = new ArrayCollection();
        $this->monstreObjets = new ArrayCollection();
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

    public function getMaxLife(): ?int
    {
        return $this->maxLife;
    }

    public function setMaxLife(int $maxLife): self
    {
        $this->maxLife = $maxLife;

        return $this;
    }

    public function getCarteCarreau(): ?CarteCarreau
    {
        return $this->carteCarreau;
    }

    public function setCarteCarreau(?CarteCarreau $carteCarreau): self
    {
        $this->carteCarreau = $carteCarreau;

        return $this;
    }

    public function getTempsRepop(): ?int
    {
        return $this->temps_repop;
    }

    public function setTempsRepop(int $temps_repop): self
    {
        $this->temps_repop = $temps_repop;

        return $this;
    }

    /**
     * @return Collection|MonstreCarreau[]
     */
    public function getQuantite(): Collection
    {
        return $this->quantite;
    }

    public function addQuantite(MonstreCarreau $quantite): self
    {
        if (!$this->quantite->contains($quantite)) {
            $this->quantite[] = $quantite;
            $quantite->setMonstre($this);
        }

        return $this;
    }

    public function removeQuantite(MonstreCarreau $quantite): self
    {
        if ($this->quantite->removeElement($quantite)) {
            // set the owning side to null (unless already changed)
            if ($quantite->getMonstre() === $this) {
                $quantite->setMonstre(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|MonstreObjet[]
     */
    public function getMonstreObjets(): Collection
    {
        return $this->monstreObjets;
    }

    public function addMonstreObjet(MonstreObjet $monstreObjet): self
    {
        if (!$this->monstreObjets->contains($monstreObjet)) {
            $this->monstreObjets[] = $monstreObjet;
            $monstreObjet->setMonstre($this);
        }

        return $this;
    }

    public function removeMonstreObjet(MonstreObjet $monstreObjet): self
    {
        if ($this->monstreObjets->removeElement($monstreObjet)) {
            // set the owning side to null (unless already changed)
            if ($monstreObjet->getMonstre() === $this) {
                $monstreObjet->setMonstre(null);
            }
        }

        return $this;
    }

    public function getSkin(): ?string
    {
        return $this->skin;
    }

    public function setSkin(string $skin): self
    {
        $this->skin = $skin;

        return $this;
    }

    public function getPuissance(): ?int
    {
        return $this->puissance;
    }

    public function setPuissance(int $puissance): self
    {
        $this->puissance = $puissance;

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
            $action->setMonstre($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getMonstre() === $this) {
                $action->setMonstre(null);
            }
        }

        return $this;
    }
}
