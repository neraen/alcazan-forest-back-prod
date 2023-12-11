<?php

namespace App\Entity;

use App\Repository\ActionFieldTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ActionFieldTypeRepository::class)
 */
class ActionFieldType
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=ActionField::class, inversedBy="actionFieldTypes")
     * @ORM\JoinColumn(nullable=false)
     */
    private $actionField;

    /**
     * @ORM\ManyToOne(targetEntity=ActionType::class, inversedBy="actionFieldTypes")
     */
    private $actionType;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActionField(): ?ActionField
    {
        return $this->actionField;
    }

    public function setActionField(?ActionField $actionField): self
    {
        $this->actionField = $actionField;

        return $this;
    }

    public function getActionType(): ?ActionType
    {
        return $this->ActionType;
    }

    public function setActionType(?ActionType $ActionType): self
    {
        $this->ActionType = $ActionType;

        return $this;
    }
}
