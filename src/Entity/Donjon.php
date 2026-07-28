<?php

namespace App\Entity;

use App\Repository\DonjonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Définition d'un donjon = CONTENU (versionné dans seeds/content-seed.sql, éditable
 * par le DonjonMaker). Toutes les règles réglables par l'admin vivent ici ; rien
 * n'est codé en dur côté service.
 *
 * L'état de partie (qui est dedans, vie du boss, verrous) vit dans les tables
 * runtime `donjon_instance*` / `donjon_verrou`, exclues du seed.
 */
#[ORM\Entity(repositoryClass: DonjonRepository::class)]
class Donjon
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

    /** Niveau minimum pour entrer (0 = pas de condition). */
    #[ORM\Column(type: 'integer')]
    private int $niveauMin = 0;

    /** Nombre maximum de joueurs par instance. */
    #[ORM\Column(type: 'integer')]
    private int $tailleGroupeMax = 5;

    /** Au-delà, l'instance est constatée EXPIREE (0 = pas de limite). */
    #[ORM\Column(type: 'integer')]
    private int $dureeMaxMinutes = 180;

    /**
     * Heure du reset quotidien des verrous (0-23), en **heure de Paris** — pas en heure
     * serveur (PHP tourne en UTC). Le « jour de donjon » commence à cette heure : à 5, la
     * session de 2 h du matin compte pour la veille. Conversion : DonjonInstanceService.
     */
    #[ORM\Column(type: 'integer')]
    private int $heureReset = 5;

    /** Un donjon inactif n'accepte plus de nouvelle instance (les instances en cours vivent). */
    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    /**
     * Où l'on repose les joueurs éjectés (instance expirée, futur wipe de groupe).
     * Typiquement la carte du monde qui contient la porte du donjon.
     */
    #[ORM\ManyToOne(targetEntity: Carte::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Carte $carteSortie = null;

    #[ORM\Column(type: 'integer')]
    private int $sortieAbscisse = 0;

    #[ORM\Column(type: 'integer')]
    private int $sortieOrdonnee = 0;

    #[ORM\OneToMany(mappedBy: 'donjon', targetEntity: DonjonSalle::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $salles;

    public function __construct()
    {
        $this->salles = new ArrayCollection();
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

    public function getNiveauMin(): int
    {
        return $this->niveauMin;
    }

    public function setNiveauMin(int $niveauMin): self
    {
        $this->niveauMin = $niveauMin;

        return $this;
    }

    public function getTailleGroupeMax(): int
    {
        return $this->tailleGroupeMax;
    }

    public function setTailleGroupeMax(int $tailleGroupeMax): self
    {
        $this->tailleGroupeMax = $tailleGroupeMax;

        return $this;
    }

    public function getDureeMaxMinutes(): int
    {
        return $this->dureeMaxMinutes;
    }

    public function setDureeMaxMinutes(int $dureeMaxMinutes): self
    {
        $this->dureeMaxMinutes = $dureeMaxMinutes;

        return $this;
    }

    public function getHeureReset(): int
    {
        return $this->heureReset;
    }

    public function setHeureReset(int $heureReset): self
    {
        $this->heureReset = $heureReset;

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

    public function getCarteSortie(): ?Carte
    {
        return $this->carteSortie;
    }

    public function setCarteSortie(?Carte $carteSortie): self
    {
        $this->carteSortie = $carteSortie;

        return $this;
    }

    public function getSortieAbscisse(): int
    {
        return $this->sortieAbscisse;
    }

    public function setSortieAbscisse(int $sortieAbscisse): self
    {
        $this->sortieAbscisse = $sortieAbscisse;

        return $this;
    }

    public function getSortieOrdonnee(): int
    {
        return $this->sortieOrdonnee;
    }

    public function setSortieOrdonnee(int $sortieOrdonnee): self
    {
        $this->sortieOrdonnee = $sortieOrdonnee;

        return $this;
    }

    /** @return Collection<int, DonjonSalle> */
    public function getSalles(): Collection
    {
        return $this->salles;
    }

    public function addSalle(DonjonSalle $salle): self
    {
        if (!$this->salles->contains($salle)) {
            $this->salles[] = $salle;
            $salle->setDonjon($this);
        }

        return $this;
    }

    public function removeSalle(DonjonSalle $salle): self
    {
        if ($this->salles->removeElement($salle) && $salle->getDonjon() === $this) {
            $salle->setDonjon(null);
        }

        return $this;
    }

    /** @return int[] ids des cartes composant le donjon */
    public function getCarteIds(): array
    {
        return array_values(array_filter(array_map(
            fn (DonjonSalle $salle) => $salle->getCarte()?->getId(),
            $this->salles->toArray()
        )));
    }
}
