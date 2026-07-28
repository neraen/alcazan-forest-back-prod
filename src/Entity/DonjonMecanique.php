<?php

namespace App\Entity;

use App\Enum\MecaniqueDonjon;
use App\Repository\DonjonMecaniqueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une mécanique de combat d'un donjon = CONTENU (versionné, éditable par le DonjonMaker).
 *
 * La « phase » n'est pas une entité : c'est la FENÊTRE DE VIE du boss (vieMax → vieMin en %)
 * qui borne la mécanique. Un boss qui invoque des renforts à 75 % puis enrage à 25 % est
 * décrit par deux lignes, sans table de phases ni code dédié.
 *
 * `params` est un JSON dont la forme dépend du type (cf. MecaniqueDonjon::parametres()).
 */
#[ORM\Entity(repositoryClass: DonjonMecaniqueRepository::class)]
#[ORM\Index(name: 'idx_donjon_mecanique_donjon', columns: ['donjon_id'])]
class DonjonMecanique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Donjon::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Donjon $donjon = null;

    #[ORM\Column(type: 'string', length: 32, enumType: MecaniqueDonjon::class)]
    private MecaniqueDonjon $type = MecaniqueDonjon::ENRAGE;

    /** Fenêtre de vie du boss (en %) dans laquelle la mécanique est active. */
    #[ORM\Column(type: 'integer')]
    private int $vieMax = 100;

    #[ORM\Column(type: 'integer')]
    private int $vieMin = 0;

    /** Délai minimum entre deux déclenchements (0 = une seule fois par phase). */
    #[ORM\Column(type: 'integer')]
    private int $cooldownSecondes = 0;

    #[ORM\Column(type: 'json')]
    private array $params = [];

    #[ORM\Column(type: 'integer')]
    private int $ordre = 1;

    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    /** Texte annoncé aux joueurs au déclenchement (français, sans HTML). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $annonce = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDonjon(): ?Donjon
    {
        return $this->donjon;
    }

    public function setDonjon(?Donjon $donjon): self
    {
        $this->donjon = $donjon;

        return $this;
    }

    public function getType(): MecaniqueDonjon
    {
        return $this->type;
    }

    public function setType(MecaniqueDonjon $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getVieMax(): int
    {
        return $this->vieMax;
    }

    public function setVieMax(int $vieMax): self
    {
        $this->vieMax = $vieMax;

        return $this;
    }

    public function getVieMin(): int
    {
        return $this->vieMin;
    }

    public function setVieMin(int $vieMin): self
    {
        $this->vieMin = $vieMin;

        return $this;
    }

    public function getCooldownSecondes(): int
    {
        return $this->cooldownSecondes;
    }

    public function setCooldownSecondes(int $cooldownSecondes): self
    {
        $this->cooldownSecondes = $cooldownSecondes;

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

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): self
    {
        $this->ordre = $ordre;

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

    public function getAnnonce(): ?string
    {
        return $this->annonce;
    }

    public function setAnnonce(?string $annonce): self
    {
        $this->annonce = $annonce;

        return $this;
    }

    /** La mécanique s'applique-t-elle à ce pourcentage de vie du boss ? */
    public function couvre(int $pourcentageVie): bool
    {
        return $this->actif && $pourcentageVie <= $this->vieMax && $pourcentageVie >= $this->vieMin;
    }
}
