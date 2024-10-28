<?php

namespace App\Entity;

use App\Repository\ActionFieldRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActionFieldRepository::class)]
class ActionField
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\Column(type: 'string', length: 255)]
    private $link;

    #[ORM\OneToMany(mappedBy: 'actionField', targetEntity: ActionFieldType::class)]
    private $actionFieldTypes;

    #[ORM\Column(type: 'string', length: 255)]
    private $type;

    public function __construct()
    {
        $this->actionFieldTypes = new ArrayCollection();
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

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(string $link): self
    {
        $this->link = $link;

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
            $actionFieldType->setActionField($this);
        }

        return $this;
    }

    public function removeActionFieldType(ActionFieldType $actionFieldType): self
    {
        if ($this->actionFieldTypes->removeElement($actionFieldType)) {
            // set the owning side to null (unless already changed)
            if ($actionFieldType->getActionField() === $this) {
                $actionFieldType->setActionField(null);
            }
        }

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
}
