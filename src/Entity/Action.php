<?php

namespace App\Entity;

use App\Enum\ActionType;
use App\Enum\QuestEffect;
use App\Repository\ActionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bouton d'une séquence de quête (ou d'une case de carte via CarteCarreau.action).
 * Le comportement est déterminé par $actionType ; les effets scriptés
 * (SCRIPTED_EFFECT) sont exécutés côté serveur via QuestEffectRegistry.
 */
#[ORM\Entity(repositoryClass: ActionRepository::class)]
class Action
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: SequenceAction::class)]
    private $sequenceActions;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: CarteCarreau::class)]
    private $carteCarreaus;

    #[ORM\Column(type: 'integer', enumType: ActionType::class)]
    private $actionType;

    #[ORM\Column(type: 'string', length: 64, nullable: true, enumType: QuestEffect::class)]
    private $effect;

    #[ORM\Column(type: 'json', nullable: true)]
    private $effectParams;

    #[ORM\ManyToOne(targetEntity: Objet::class, inversedBy: 'actions')]
    private $objet;

    #[ORM\ManyToOne(targetEntity: Equipement::class, inversedBy: 'actions')]
    private $equipement;

    #[ORM\ManyToOne(targetEntity: Consommable::class, inversedBy: 'actions')]
    private $consommable;

    #[ORM\ManyToOne(targetEntity: Boss::class, inversedBy: 'actions')]
    private $boss;

    #[ORM\ManyToOne(targetEntity: Pnj::class, inversedBy: 'actions')]
    private $pnj;

    #[ORM\ManyToOne(targetEntity: Monstre::class, inversedBy: 'actions')]
    private $monstre;

    #[ORM\ManyToOne(targetEntity: Carte::class, inversedBy: 'actions')]
    private $carte;

    /** Recette à fabriquer (FABRIQUER_OBJET). */
    #[ORM\ManyToOne(targetEntity: Recette::class)]
    private ?Recette $recette = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $quantity;

    /**
     * Karma gagné (positif) ou perdu (négatif) quand ce bouton est joué.
     *
     * Porté par l'ACTION et non par la séquence : c'est le choix du joueur qui a un
     * poids moral, pas le fait d'avoir lu un dialogue. Deux boutons d'une même
     * séquence — « je tiens parole » / « je garde l'or » — sont exactement le
     * dispositif que cette colonne sert à rendre possible.
     *
     * null ou 0 = ce choix n'engage rien. L'ajustement passe TOUJOURS par KarmaService,
     * qui borne la valeur : le contenu ne peut pas fabriquer un saint définitif.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $karma = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private $message;

    /**
     * Branchement : séquence vers laquelle sauter après ce choix (au lieu du
     * position + 1 linéaire). null = comportement linéaire par défaut.
     */
    #[ORM\ManyToOne(targetEntity: Sequence::class)]
    private $nextSequence;

    /** Ce choix termine la quête (prioritaire sur nextSequence). */
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $endsQuest;

    /** Récompense donnée quand ce choix est joué (une par action = par branche). */
    #[ORM\OneToOne(mappedBy: 'action', targetEntity: Recompense::class)]
    private $recompense;


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

    public function getEffect(): ?QuestEffect
    {
        return $this->effect;
    }

    public function setEffect(?QuestEffect $effect): self
    {
        $this->effect = $effect;

        return $this;
    }

    public function getEffectParams(): ?array
    {
        return $this->effectParams;
    }

    public function setEffectParams(?array $effectParams): self
    {
        $this->effectParams = $effectParams;

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

    public function setActionType(ActionType $actionType): self
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

    public function getRecette(): ?Recette
    {
        return $this->recette;
    }

    public function setRecette(?Recette $recette): self
    {
        $this->recette = $recette;

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

    public function getKarma(): ?int
    {
        return $this->karma;
    }

    public function setKarma(?int $karma): self
    {
        $this->karma = $karma;

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

    public function getNextSequence(): ?Sequence
    {
        return $this->nextSequence;
    }

    public function setNextSequence(?Sequence $nextSequence): self
    {
        $this->nextSequence = $nextSequence;

        return $this;
    }

    public function getEndsQuest(): ?bool
    {
        return $this->endsQuest;
    }

    public function setEndsQuest(?bool $endsQuest): self
    {
        $this->endsQuest = $endsQuest;

        return $this;
    }

    public function getRecompense(): ?Recompense
    {
        return $this->recompense;
    }

    public function setRecompense(?Recompense $recompense): self
    {
        $this->recompense = $recompense;

        return $this;
    }
}
