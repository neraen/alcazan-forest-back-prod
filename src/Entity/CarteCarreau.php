<?php

namespace App\Entity;

use App\Repository\CarteCarreauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CarteCarreauRepository::class)]
class CarteCarreau
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Carte::class, inversedBy: 'carteCarreaus')]
    private $carte;

    #[ORM\ManyToOne(targetEntity: Carreau::class, inversedBy: 'carteCarreaux')]
    private $carreau;

    #[ORM\Column(type: 'integer')]
    private $abscisse;

    #[ORM\Column(type: 'integer')]
    private $ordonnee;

    #[ORM\OneToOne(inversedBy: 'carteCarreau', targetEntity: User::class, cascade: ['persist', 'remove'])]
    private $joueur;

    #[ORM\OneToOne(mappedBy: 'carteCarreau', targetEntity: Monstre::class, cascade: ['persist', 'remove'])]
    private $monstres;


    #[ORM\ManyToOne(targetEntity: MonstreCarreau::class, inversedBy: 'carteCarreaus')]
    private $monstreCarreau;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $targetMapId;

    #[ORM\ManyToOne(targetEntity: Pnj::class, inversedBy: 'carteCarreau')]
    private $pnj;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $targetWrap;

    #[ORM\Column(type: 'boolean')]
    private $isUsable;

    #[ORM\Column(type: 'boolean')]
    private $isWrap;

    #[ORM\OneToMany(mappedBy: 'carteCarreau', targetEntity: MonstreCarreau::class)]
    private $monstreCarreaus;

    #[ORM\ManyToOne(targetEntity: Action::class, inversedBy: 'carteCarreaus')]
    private $action;

    #[ORM\ManyToOne(targetEntity: Boss::class, inversedBy: 'carteCarreaus')]
    private $boss;

    #[ORM\ManyToOne(targetEntity: Wrap::class, inversedBy: 'carteCarreaus')]
    private $wrap;

    public function __construct()
    {
        $this->monstreCarreaus = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCarte(): ?Carte
    {
        return $this->carte;
    }

    public function setCarte(?Carte $carte): self
    {
        $this->carte = $carte;

        return $this;
    }

    public function getCarreau(): ?Carreau
    {
        return $this->carreau;
    }

    public function setCarreau(?Carreau $carreau): self
    {
        $this->carreau = $carreau;

        return $this;
    }


    public function getAbscisse(): ?int
    {
        return $this->abscisse;
    }

    public function setAbscisse(int $abscisse): self
    {
        $this->abscisse = $abscisse;

        return $this;
    }

    public function getOrdonnee(): ?int
    {
        return $this->ordonnee;
    }

    public function setOrdonnee(int $ordonnee): self
    {
        $this->ordonnee = $ordonnee;

        return $this;
    }

    public function getJoueur(): ?User
    {
        return $this->joueur;
    }

    public function setJoueur(?User $joueur): self
    {
        $this->joueur = $joueur;

        return $this;
    }

    public function getMonstres(): ?Monstre
    {
        return $this->monstres;
    }

    public function setMonstres(?Monstre $monstres): self
    {
        // unset the owning side of the relation if necessary
        if ($monstres === null && $this->monstres !== null) {
            $this->monstres->setCarteCarreau(null);
        }

        // set the owning side of the relation if necessary
        if ($monstres !== null && $monstres->getCarteCarreau() !== $this) {
            $monstres->setCarteCarreau($this);
        }

        $this->monstres = $monstres;

        return $this;
    }

    public function getPnj(): ?Pnj
    {
        return $this->pnj;
    }

    public function getMonstreCarreau(): ?MonstreCarreau
    {
        return $this->monstreCarreau;
    }

    public function setMonstreCarreau(?MonstreCarreau $monstreCarreau): self
    {
        $this->monstreCarreau = $monstreCarreau;

        return $this;
    }

    public function getTargetMapId(): ?int
    {
        return $this->targetMapId;
    }

    public function setTargetMapId(?int $targetMapId): self
    {
        $this->targetMapId = $targetMapId;

        return $this;
    }

    public function setPnj(?Pnj $pnj): self
    {
        $this->pnj = $pnj;

        return $this;
    }

    public function getTargetWrap(): ?int
    {
        return $this->targetWrap;
    }

    public function setTargetWrap(?int $targetWrap): self
    {
        $this->targetWrap = $targetWrap;

        return $this;
    }

    public function getIsUsable(): ?bool
    {
        return $this->isUsable;
    }

    public function setIsUsable(bool $isUsable): self
    {
        $this->isUsable = $isUsable;

        return $this;
    }

    public function getIsWrap(): ?bool
    {
        return $this->isWrap;
    }

    public function setIsWrap(bool $isWrap): self
    {
        $this->isWrap = $isWrap;

        return $this;
    }

    /**
     * @return Collection|MonstreCarreau[]
     */
    public function getMonstreCarreaus(): Collection
    {
        return $this->monstreCarreaus;
    }

    public function addMonstreCarreau(MonstreCarreau $monstreCarreau): self
    {
        if (!$this->monstreCarreaus->contains($monstreCarreau)) {
            $this->monstreCarreaus[] = $monstreCarreau;
            $monstreCarreau->setCarteCarreau($this);
        }

        return $this;
    }

    public function removeMonstreCarreau(MonstreCarreau $monstreCarreau): self
    {
        if ($this->monstreCarreaus->removeElement($monstreCarreau)) {
            // set the owning side to null (unless already changed)
            if ($monstreCarreau->getCarteCarreau() === $this) {
                $monstreCarreau->setCarteCarreau(null);
            }
        }

        return $this;
    }

    public function getAction(): ?Action
    {
        return $this->action;
    }

    public function setAction(?Action $action): self
    {
        $this->action = $action;

        return $this;
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

    public function getWrap(): ?Wrap
    {
        return $this->wrap;
    }

    public function setWrap(?Wrap $wrap): self
    {
        $this->wrap = $wrap;

        return $this;
    }
}
