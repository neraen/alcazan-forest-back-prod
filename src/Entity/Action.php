<?php

namespace App\Entity;

use App\Repository\ActionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ActionRepository::class)
 */
class Action
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
    private $api_link;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $params;

    /**
     * @ORM\OneToMany(targetEntity=SequenceAction::class, mappedBy="action")
     */
    private $sequenceActions;

    /**
     * @ORM\OneToMany(targetEntity=CarteCarreau::class, mappedBy="action")
     */
    private $carteCarreaus;

    /**
     * @ORM\ManyToOne(targetEntity=ActionType::class, inversedBy="actions")
     */
    private $actionType;

    /**
     * @ORM\ManyToOne(targetEntity=Objet::class, inversedBy="actions")
     */
    private $objet;

    /**
     * @ORM\ManyToOne(targetEntity=Equipement::class, inversedBy="actions")
     */
    private $equipement;

    /**
     * @ORM\ManyToOne(targetEntity=Consommable::class, inversedBy="actions")
     */
    private $consommable;

    /**
     * @ORM\ManyToOne(targetEntity=Boss::class, inversedBy="actions")
     */
    private $boss;

    /**
     * @ORM\ManyToOne(targetEntity=Pnj::class, inversedBy="actions")
     */
    private $pnj;

    /**
     * @ORM\ManyToOne(targetEntity=Monstre::class, inversedBy="actions")
     */
    private $monstre;

    /**
     * @ORM\ManyToOne(targetEntity=Carte::class, inversedBy="actions")
     */
    private $carte;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $quantity;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $message;



    public function __construct()
    {
        $this->sequenceActions = new ArrayCollection();
        $this->carteCarreaus = new ArrayCollection();
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

    public function getApiLink(): ?string
    {
        return $this->api_link;
    }

    public function setApiLink(string $api_link): self
    {
        $this->api_link = $api_link;

        return $this;
    }

    public function getParams(): ?string
    {
        return $this->params;
    }

    public function setParams(?string $params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * @return Collection|SequenceAction[]
     */
    public function getSequenceActions(): Collection
    {
        return $this->sequenceActions;
    }

    public function addSequenceAction(SequenceAction $sequenceAction): self
    {
        if (!$this->sequenceActions->contains($sequenceAction)) {
            $this->sequenceActions[] = $sequenceAction;
            $sequenceAction->setAction($this);
        }

        return $this;
    }

    public function removeSequenceAction(SequenceAction $sequenceAction): self
    {
        if ($this->sequenceActions->removeElement($sequenceAction)) {
            // set the owning side to null (unless already changed)
            if ($sequenceAction->getAction() === $this) {
                $sequenceAction->setAction(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|CarteCarreau[]
     */
    public function getCarteCarreaus(): Collection
    {
        return $this->carteCarreaus;
    }

    public function addCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if (!$this->carteCarreaus->contains($carteCarreau)) {
            $this->carteCarreaus[] = $carteCarreau;
            $carteCarreau->setAction($this);
        }

        return $this;
    }

    public function removeCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if ($this->carteCarreaus->removeElement($carteCarreau)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreau->getAction() === $this) {
                $carteCarreau->setAction(null);
            }
        }

        return $this;
    }

    public function getActionType(): ?ActionType
    {
        return $this->actionType;
    }

    public function setActionType(?ActionType $actionType): self
    {
        $this->actionType = $actionType;

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

    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;

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

    public function getBoss(): ?Boss
    {
        return $this->boss;
    }

    public function setBoss(?Boss $boss): self
    {
        $this->boss = $boss;

        return $this;
    }

    public function getPnj(): ?Pnj
    {
        return $this->pnj;
    }

    public function setPnj(?Pnj $pnj): self
    {
        $this->pnj = $pnj;

        return $this;
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

    public function getCarte(): ?Carte
    {
        return $this->carte;
    }

    public function setCarte(?Carte $carte): self
    {
        $this->carte = $carte;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }
}
