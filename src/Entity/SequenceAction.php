<?php

namespace App\Entity;

use App\Repository\SequenceActionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=SequenceActionRepository::class)
 */
class SequenceAction
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=Sequence::class, inversedBy="sequenceActions")
     */
    private $sequence;

    /**
     * @ORM\ManyToOne(targetEntity=Action::class, inversedBy="sequenceActions")
     * @ORM\JoinColumn(nullable=false)
     */
    private $action;

    /**
     * @ORM\Column(type="integer")
     */
    private $position;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSequence(): ?Sequence
    {
        return $this->sequence;
    }

    public function setSequence(?Sequence $sequence): self
    {
        $this->sequence = $sequence;

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

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
