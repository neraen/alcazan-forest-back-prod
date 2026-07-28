<?php

namespace App\Entity;

use App\Enum\FamilleMetier;
use App\Repository\MetierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un métier = CONTENU (versionné, éditable en admin). Volontairement minimal : nom,
 * description, icône, famille. La progression vit dans JoueurMetier ; les recettes sont
 * une entité à part et n'ont rien à faire ici.
 *
 * La `famille` n'est pas décorative : c'est elle qui rend calculable le plafond
 * « 2 métiers de récolte, 3 de craft » (cf. ArtisanatConfig).
 */
#[ORM\Entity(repositoryClass: MetierRepository::class)]
class Metier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $nom = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $icone = null;

    #[ORM\Column(type: 'string', length: 16, enumType: FamilleMetier::class)]
    private FamilleMetier $famille = FamilleMetier::RECOLTE;

    /** Plafond de progression (0 = pas de plafond). */
    #[ORM\Column(type: 'integer')]
    private int $niveauMax = 200;

    /** Maîtres de métier qui l'enseignent (PNJ de type « metier »). */
    #[ORM\ManyToMany(targetEntity: Pnj::class, mappedBy: 'metiers')]
    private Collection $maitres;

    public function __construct()
    {
        $this->maitres = new ArrayCollection();
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

    public function getIcone(): ?string
    {
        return $this->icone;
    }

    public function setIcone(?string $icone): self
    {
        $this->icone = $icone;

        return $this;
    }

    public function getFamille(): FamilleMetier
    {
        return $this->famille;
    }

    public function setFamille(FamilleMetier $famille): self
    {
        $this->famille = $famille;

        return $this;
    }

    public function getNiveauMax(): int
    {
        return $this->niveauMax;
    }

    public function setNiveauMax(int $niveauMax): self
    {
        $this->niveauMax = $niveauMax;

        return $this;
    }

    /** @return Collection<int, Pnj> */
    public function getMaitres(): Collection
    {
        return $this->maitres;
    }
}
