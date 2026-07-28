<?php

namespace App\Entity;

use App\Repository\RecetteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une recette de fabrication = CONTENU (versionné, éditable par l'ArtisanatMaker).
 *
 * **La sortie est une `Recompense`**, et c'est un choix : `RecompenseService` est l'unique
 * point de conversion « ligne de récompense → items + or + XP » du projet. S'en écarter
 * pour le craft aurait dupliqué la distribution d'items — exactement ce que l'invariant
 * interdit. Une recette est donc « des ingrédients, du temps, et une récompense ».
 *
 * `experienceMetier` est SAISIE et non calculée : une formule « niveau × difficulté »
 * enfermerait l'équilibrage dans le code. L'éditeur pré-remplit une suggestion, l'auteur
 * tranche.
 */
#[ORM\Entity(repositoryClass: RecetteRepository::class)]
class Recette
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $nom = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Metier $metier = null;

    /** Niveau de métier exigé pour lancer la fabrication. */
    #[ORM\Column(type: 'integer')]
    private int $niveauRequis = 1;

    /** Indice de difficulté (1 à 5) : purement descriptif, sert à suggérer l'XP. */
    #[ORM\Column(type: 'integer')]
    private int $difficulte = 1;

    /** Temps de production de base, avant multiplicateur de mode. */
    #[ORM\Column(type: 'integer')]
    private int $tempsSecondes = 60;

    /** Ce que la fabrication produit, distribué par RecompenseService. */
    #[ORM\ManyToOne(targetEntity: Recompense::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Recompense $recompense = null;

    #[ORM\Column(type: 'integer')]
    private int $experienceMetier = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    #[ORM\OneToMany(mappedBy: 'recette', targetEntity: RecetteIngredient::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $ingredients;

    public function __construct()
    {
        $this->ingredients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getMetier(): ?Metier
    {
        return $this->metier;
    }

    public function setMetier(?Metier $metier): self
    {
        $this->metier = $metier;

        return $this;
    }

    public function getNiveauRequis(): int
    {
        return $this->niveauRequis;
    }

    public function setNiveauRequis(int $niveauRequis): self
    {
        $this->niveauRequis = max(1, $niveauRequis);

        return $this;
    }

    public function getDifficulte(): int
    {
        return $this->difficulte;
    }

    public function setDifficulte(int $difficulte): self
    {
        $this->difficulte = max(1, min(5, $difficulte));

        return $this;
    }

    public function getTempsSecondes(): int
    {
        return $this->tempsSecondes;
    }

    public function setTempsSecondes(int $tempsSecondes): self
    {
        $this->tempsSecondes = max(1, $tempsSecondes);

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

    public function getExperienceMetier(): int
    {
        return $this->experienceMetier;
    }

    public function setExperienceMetier(int $experienceMetier): self
    {
        $this->experienceMetier = max(0, $experienceMetier);

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    /** @return Collection<int, RecetteIngredient> */
    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    public function addIngredient(RecetteIngredient $ingredient): self
    {
        if (!$this->ingredients->contains($ingredient)) {
            $this->ingredients[] = $ingredient;
            $ingredient->setRecette($this);
        }

        return $this;
    }

    public function removeIngredient(RecetteIngredient $ingredient): self
    {
        $this->ingredients->removeElement($ingredient);

        return $this;
    }
}
