<?php

namespace App\Entity;

use App\Repository\ActionTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ActionTypeRepository::class)
 */
class ActionType
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
     * @ORM\OneToMany(targetEntity=ActionFieldType::class, mappedBy="ActionType")
     */
    private $actionFieldTypes;

    /**
     * @ORM\Column(type="boolean")
     */
    private $isRecursive;

    /**
     * @ORM\OneToMany(targetEntity=Action::class, mappedBy="actionType")
     */
    private $actions;

    public function __construct()
    {
        $this->actionFieldTypes = new ArrayCollection();
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

    /**
     * @return Collection<int, ActionFieldType>
     */
    public function getActionFieldTypes(): Collection
    {
        return $this->actionFieldTypes;
    }

    public function addActionFieldType(ActionFieldType $actionFieldType): self
    {
        if (!$this->actionFieldTypes->contains($actionFieldType)) {
            $this->actionFieldTypes[] = $actionFieldType;
            $actionFieldType->setActionType($this);
        }

        return $this;
    }

    public function removeActionFieldType(ActionFieldType $actionFieldType): self
    {
        if ($this->actionFieldTypes->removeElement($actionFieldType)) {
            // set the owning side to null (unless already changed)
            if ($actionFieldType->getActionType() === $this) {
                $actionFieldType->setActionType(null);
            }
        }

        return $this;
    }

    public function getIsRecursive(): ?bool
    {
        return $this->isRecursive;
    }

    public function setIsRecursive(bool $isRecursive): self
    {
        $this->isRecursive = $isRecursive;

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
            $action->setActionType($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getActionType() === $this) {
                $action->setActionType(null);
            }
        }

        return $this;
    }
}
