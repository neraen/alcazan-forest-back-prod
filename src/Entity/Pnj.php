<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\PnjRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=PnjRepository::class)
 */
#[ApiResource]
class Pnj
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
     * @ORM\Column(type="string", length=255)
     */
    private $avatar;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $skin;

    /**
     * @ORM\OneToMany(targetEntity=Sequence::class, mappedBy="pnj")
     */
    private $sequences;

    /**
     * @ORM\OneToMany(targetEntity=CarteCarreau::class, mappedBy="pnj")
     */
    private $carteCarreau;

    /**
     * @ORM\Column(type="string", length=500)
     */
    private $description;

    /**
     * @ORM\Column(type="string", length=30)
     */
    private $type;

    /**
     * @ORM\ManyToOne(targetEntity=Shop::class, inversedBy="pnjs")
     */
    private $shop;

    /**
     * @ORM\ManyToOne(targetEntity=Quete::class, inversedBy="pnjs")
     */
    private $quete;

    /**
     * @ORM\OneToMany(targetEntity=Action::class, mappedBy="pnj")
     */
    private $actions;

    public function __construct()
    {
        $this->sequences = new ArrayCollection();
        $this->carteCarreau = new ArrayCollection();
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

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(string $avatar): self
    {
        $this->avatar = $avatar;

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

    /**
     * @return Collection|Sequence[]
     */
    public function getSequences(): Collection
    {
        return $this->sequences;
    }

    public function addSequence(Sequence $sequence): self
    {
        if (!$this->sequences->contains($sequence)) {
            $this->sequences[] = $sequence;
            $sequence->setPnj($this);
        }

        return $this;
    }

    public function removeSequence(Sequence $sequence): self
    {
        if ($this->sequences->removeElement($sequence)) {
            // set the owning side to null (unless already changed)
            if ($sequence->getPnj() === $this) {
                $sequence->setPnj(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|CarteCarreau[]
     */
    public function getCarteCarreau(): Collection
    {
        return $this->carteCarreau;
    }

    public function addCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if (!$this->carteCarreau->contains($carteCarreau)) {
            $this->carteCarreau[] = $carteCarreau;
            $carteCarreau->setPnj($this);
        }

        return $this;
    }

    public function removeCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if ($this->carteCarreau->removeElement($carteCarreau)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreau->getPnj() === $this) {
                $carteCarreau->setPnj(null);
            }
        }

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

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

    public function getShop(): ?Shop
    {
        return $this->shop;
    }

    public function setShop(?Shop $shop): self
    {
        $this->shop = $shop;

        return $this;
    }

    public function getQuete(): ?Quete
    {
        return $this->quete;
    }

    public function setQuete(?Quete $quete): self
    {
        $this->quete = $quete;

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
            $action->setPnj($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getPnj() === $this) {
                $action->setPnj(null);
            }
        }

        return $this;
    }
}
