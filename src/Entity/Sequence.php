<?php

namespace App\Entity;

use App\Repository\SequenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Étape d'une quête (ordonnée par position), ou dialogue autonome d'un PNJ
 * de type "action" quand $quete est null. Le dialogue est porté par la
 * séquence elle-même (titre + contenu), les boutons par $sequenceActions.
 */
#[ORM\Entity(repositoryClass: SequenceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sequence_quete_position', columns: ['quete_id', 'position'])]
class Sequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'integer')]
    private $position;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Pnj::class, inversedBy: 'sequences')]
    private $pnj;

    #[ORM\OneToMany(mappedBy: 'sequence', targetEntity: SequenceAction::class)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private $sequenceActions;

    #[ORM\ManyToOne(targetEntity: Quete::class, inversedBy: 'sequences')]
    private $quete;

    #[ORM\OneToOne(mappedBy: 'sequence', targetEntity: Recompense::class)]
    private $recompense;

    #[ORM\OneToMany(mappedBy: 'sequence', targetEntity: UserQuete::class)]
    private $userQuetes;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $dialogueTitre;

    #[ORM\Column(type: 'text', nullable: true)]
    private $dialogueContenu;

    public function __construct()
    {
        $this->sequenceActions = new ArrayCollection();
        $this->userQuetes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

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
            $sequenceAction->setSequence($this);
        }

        return $this;
    }

    public function removeSequenceAction(SequenceAction $sequenceAction): self
    {
        if ($this->sequenceActions->removeElement($sequenceAction)) {
            // set the owning side to null (unless already changed)
            if ($sequenceAction->getSequence() === $this) {
                $sequenceAction->setSequence(null);
            }
        }

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

    public function getRecompense(): ?Recompense
    {
        return $this->recompense;
    }

    public function setRecompense(?Recompense $recompense): self
    {
        $this->recompense = $recompense;

        return $this;
    }

    /**
     * @return Collection|UserQuete[]
     */
    public function getUserQuetes(): Collection
    {
        return $this->userQuetes;
    }

    public function addUserQuete(UserQuete $userQuete): self
    {
        if (!$this->userQuetes->contains($userQuete)) {
            $this->userQuetes[] = $userQuete;
            $userQuete->setSequence($this);
        }

        return $this;
    }

    public function removeUserQuete(UserQuete $userQuete): self
    {
        if ($this->userQuetes->removeElement($userQuete)) {
            // set the owning side to null (unless already changed)
            if ($userQuete->getSequence() === $this) {
                $userQuete->setSequence(null);
            }
        }

        return $this;
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

    public function getDialogueTitre(): ?string
    {
        return $this->dialogueTitre;
    }

    public function setDialogueTitre(?string $dialogueTitre): self
    {
        $this->dialogueTitre = $dialogueTitre;

        return $this;
    }

    public function getDialogueContenu(): ?string
    {
        return $this->dialogueContenu;
    }

    public function setDialogueContenu(?string $dialogueContenu): self
    {
        $this->dialogueContenu = $dialogueContenu;

        return $this;
    }
}
