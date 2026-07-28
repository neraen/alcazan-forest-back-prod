<?php

namespace App\Entity;

use App\Enum\TypeConditionInteraction;
use App\Repository\InteractionConditionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une condition d'accès à une interaction = CONTENU (versionné).
 *
 * Table fille plutôt que colonnes sur `Interaction` : le nombre de conditions varie, et
 * en ajouter un type ne doit jamais demander une migration de schéma. `params` est un
 * JSON dont la forme dépend du type (cf. TypeConditionInteraction::parametres()).
 */
#[ORM\Entity(repositoryClass: InteractionConditionRepository::class)]
#[ORM\Index(name: 'idx_interaction_condition', columns: ['interaction_id'])]
class InteractionCondition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Interaction::class, inversedBy: 'conditions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Interaction $interaction = null;

    #[ORM\Column(type: 'string', length: 32, enumType: TypeConditionInteraction::class)]
    private TypeConditionInteraction $type = TypeConditionInteraction::NIVEAU;

    #[ORM\Column(type: 'json')]
    private array $params = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInteraction(): ?Interaction
    {
        return $this->interaction;
    }

    public function setInteraction(?Interaction $interaction): self
    {
        $this->interaction = $interaction;

        return $this;
    }

    public function getType(): TypeConditionInteraction
    {
        return $this->type;
    }

    public function setType(TypeConditionInteraction $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function param(string $cle, mixed $defaut = null): mixed
    {
        return $this->params[$cle] ?? $this->type->parametres()[$cle] ?? $defaut;
    }
}
