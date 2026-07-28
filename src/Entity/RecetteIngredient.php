<?php

namespace App\Entity;

use App\Enum\TypeItem;
use App\Repository\RecetteIngredientRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un ingrédient d'une recette = CONTENU.
 *
 * Trois relations nullables plutôt qu'un couple (type, id) sans clé étrangère : c'est la
 * forme de `Recompense`, `ShopObjet` et compagnie, et elle laisse la base garantir que
 * l'ingrédient existe. Supprimer un objet encore employé dans une recette échouera —
 * c'est le comportement voulu.
 */
#[ORM\Entity(repositoryClass: RecetteIngredientRepository::class)]
#[ORM\Index(name: 'idx_recette_ingredient', columns: ['recette_id'])]
class RecetteIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Recette::class, inversedBy: 'ingredients')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Recette $recette = null;

    #[ORM\ManyToOne(targetEntity: Objet::class)]
    private ?Objet $objet = null;

    #[ORM\ManyToOne(targetEntity: Equipement::class)]
    private ?Equipement $equipement = null;

    #[ORM\ManyToOne(targetEntity: Consommable::class)]
    private ?Consommable $consommable = null;

    #[ORM\Column(type: 'integer')]
    private int $quantite = 1;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = max(1, $quantite);

        return $this;
    }

    /** Famille d'item de cet ingrédient, ou null si la ligne est incomplète. */
    public function getType(): ?TypeItem
    {
        return match (true) {
            $this->objet !== null => TypeItem::OBJET,
            $this->equipement !== null => TypeItem::EQUIPEMENT,
            $this->consommable !== null => TypeItem::CONSOMMABLE,
            default => null,
        };
    }

    public function getItemId(): ?int
    {
        return $this->objet?->getId() ?? $this->equipement?->getId() ?? $this->consommable?->getId();
    }

    public function getNom(): string
    {
        return $this->objet?->getName()
            ?? $this->equipement?->getNom()
            ?? $this->consommable?->getNom()
            ?? '???';
    }
}
