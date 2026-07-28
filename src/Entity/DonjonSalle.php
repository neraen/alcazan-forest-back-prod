<?php

namespace App\Entity;

use App\Enum\ConditionSalleDonjon;
use App\Enum\TypeSalleDonjon;
use App\Repository\DonjonSalleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une carte rattachée à un donjon. CONTENU (versionné).
 *
 * Une carte n'appartient qu'à UN donjon (index unique sur carte_id) : c'est ce qui
 * permet de retrouver le donjon depuis une carte sans ambiguïté au moment d'un
 * changement de carte.
 */
#[ORM\Entity(repositoryClass: DonjonSalleRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_donjon_salle_carte', columns: ['carte_id'])]
class DonjonSalle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Donjon::class, inversedBy: 'salles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Donjon $donjon = null;

    #[ORM\ManyToOne(targetEntity: Carte::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Carte $carte = null;

    /** Ordre de traversée, 1 = entrée. */
    #[ORM\Column(type: 'integer')]
    private int $ordre = 1;

    #[ORM\Column(type: 'string', length: 32, enumType: TypeSalleDonjon::class)]
    private TypeSalleDonjon $type = TypeSalleDonjon::COULOIR;

    /**
     * Ce qu'il faut avoir accompli dans la salle PRÉCÉDENTE pour entrer dans celle-ci.
     *
     * ⚠️ `condition` est un MOT RÉSERVÉ MySQL : le nom de colonne doit être déclaré entre
     * backticks pour que Doctrine l'échappe dans le SQL qu'il génère. La lecture passait
     * (un nom qualifié `d0_.condition` est toléré), mais tout INSERT/UPDATE de l'ORM
     * partait en erreur de syntaxe 1064 — le DonjonMaker ne pouvait donc RIEN enregistrer.
     */
    #[ORM\Column(name: '`condition`', type: 'string', length: 32, enumType: ConditionSalleDonjon::class)]
    private ConditionSalleDonjon $condition = ConditionSalleDonjon::AUCUNE;

    #[ORM\Column(type: 'json')]
    private array $conditionParams = [];

    /**
     * Population qui apparaît à l'arrivée du groupe. Elle est créée dans
     * `donjon_instance_monstre` — PAS dans `monstre_carreau`, qui est attachée au décor
     * et serait donc partagée entre tous les groupes.
     */
    #[ORM\ManyToOne(targetEntity: Monstre::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Monstre $monstre = null;

    #[ORM\Column(type: 'integer')]
    private int $nombreMonstres = 0;

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

    public function getCarte(): ?Carte
    {
        return $this->carte;
    }

    public function setCarte(?Carte $carte): self
    {
        $this->carte = $carte;

        return $this;
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

    public function getType(): TypeSalleDonjon
    {
        return $this->type;
    }

    public function setType(TypeSalleDonjon $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCondition(): ConditionSalleDonjon
    {
        return $this->condition;
    }

    public function setCondition(ConditionSalleDonjon $condition): self
    {
        $this->condition = $condition;

        return $this;
    }

    public function getConditionParams(): array
    {
        return $this->conditionParams;
    }

    public function setConditionParams(array $conditionParams): self
    {
        $this->conditionParams = $conditionParams;

        return $this;
    }

    public function conditionParam(string $cle, mixed $defaut = null): mixed
    {
        return $this->conditionParams[$cle] ?? $this->condition->parametres()[$cle] ?? $defaut;
    }

    public function getMonstre(): ?Monstre
    {
        return $this->monstre;
    }

    public function setMonstre(?Monstre $monstre): self
    {
        $this->monstre = $monstre;

        return $this;
    }

    public function getNombreMonstres(): int
    {
        return $this->nombreMonstres;
    }

    public function setNombreMonstres(int $nombreMonstres): self
    {
        $this->nombreMonstres = max(0, $nombreMonstres);

        return $this;
    }

    public function aUnePopulation(): bool
    {
        return $this->monstre !== null && $this->nombreMonstres > 0;
    }
}
