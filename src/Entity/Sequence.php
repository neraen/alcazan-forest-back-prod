<?php

namespace App\Entity;

use App\Repository\SequenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=SequenceRepository::class)
 */
class Sequence
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     */
    private $position;

    /**
     * @ORM\Column(type="boolean")
     */
    private $is_last;

    /**
     * @ORM\ManyToOne(targetEntity=Pnj::class, inversedBy="sequences")
     * @ORM\JoinColumn(nullable=false)
     */
    private $pnj;

    /**
     * @ORM\ManyToOne(targetEntity=Dialogue::class, inversedBy="sequences")
     */
    private $dialogue;

    /**
     * @ORM\OneToMany(targetEntity=SequenceAction::class, mappedBy="sequence")
     */
    private $sequenceActions;

    /**
     * @ORM\OneToMany(targetEntity=UserSequence::class, mappedBy="Sequence")
     */
    private $userSequences;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $has_action;

    /**
     * @ORM\ManyToOne(targetEntity=Quete::class, inversedBy="sequences")
     */
    private $quete;

    /**
     * @ORM\OneToMany(targetEntity=Recompense::class, mappedBy="sequence")
     */
    private $recompenses;

    /**
     * @ORM\OneToMany(targetEntity=UserQuete::class, mappedBy="sequence")
     */
    private $userQuetes;

    /**
     * @ORM\ManyToOne(targetEntity=Sequence::class)
     */
    private $lastSequence;

    /**
     * @ORM\ManyToOne(targetEntity=Sequence::class)
     */
    private $nextSequence;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $name;


    public function __construct()
    {
        $this->dialogues = new ArrayCollection();
        $this->sequenceActions = new ArrayCollection();
        $this->userSequences = new ArrayCollection();
        $this->recompenses = new ArrayCollection();
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

    public function getIsLast(): ?bool
    {
        return $this->is_last;
    }

    public function setIsLast(bool $is_last): self
    {
        $this->is_last = $is_last;

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

    public function getDialogue(): ?Dialogue
    {
        return $this->dialogue;
    }

    public function setDialogue(?Dialogue $dialogue): self
    {
        $this->dialogue = $dialogue;

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

    /**
     * @return Collection|UserSequence[]
     */
    public function getUserSequences(): Collection
    {
        return $this->userSequences;
    }

    public function addUserSequence(UserSequence $userSequence): self
    {
        if (!$this->userSequences->contains($userSequence)) {
            $this->userSequences[] = $userSequence;
            $userSequence->setSequence($this);
        }

        return $this;
    }

    public function removeUserSequence(UserSequence $userSequence): self
    {
        if ($this->userSequences->removeElement($userSequence)) {
            // set the owning side to null (unless already changed)
            if ($userSequence->getSequence() === $this) {
                $userSequence->setSequence(null);
            }
        }

        return $this;
    }

    public function getHasAction(): ?bool
    {
        return $this->has_action;
    }

    public function setHasAction(?bool $has_action): self
    {
        $this->has_action = $has_action;

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
            $recompense->setSequence($this);
        }

        return $this;
    }

    public function removeRecompense(Recompense $recompense): self
    {
        if ($this->recompenses->removeElement($recompense)) {
            // set the owning side to null (unless already changed)
            if ($recompense->getSequence() === $this) {
                $recompense->setSequence(null);
            }
        }

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

    public function getLastSequence(): ?self
    {
        return $this->lastSequence;
    }

    public function setLastSequence(?self $lastSequence): self
    {
        $this->lastSequence = $lastSequence;

        return $this;
    }

    public function getNextSequence(): ?self
    {
        return $this->nextSequence;
    }

    public function setNextSequence(?self $nextSequence): self
    {
        $this->nextSequence = $nextSequence;

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
}
