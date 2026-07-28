<?php

namespace App\Entity;

use App\Enum\PorteeRecharge;
use App\Enum\QuestEffect;
use App\Enum\TypeInteraction;
use App\Repository\InteractionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un point interactif posable sur une case de carte = CONTENU (versionné, éditable
 * par l'InteractionMaker) : ressource à récolter, coffre, levier, mécanisme.
 *
 * VOLONTAIREMENT DISTINCTE d'`Action`, qui est un bouton de séquence de quête chargé de
 * ses propres préoccupations (nextSequence, endsQuest, six relations de contenu). Fusionner
 * les deux aurait donné une entité fourre-tout où un coffre de forêt traînerait des
 * colonnes de branchement de dialogue.
 *
 * Ce que fait une interaction se décompose en trois choses indépendantes :
 *   - une RÉCOMPENSE (`Recompense`, distribuée par RecompenseService — l'unique point
 *     de conversion « ligne récompense → items + or + XP ») ;
 *   - un EFFET scripté optionnel (`QuestEffect`, la whitelist serveur existante) — c'est
 *     ce qui permet à une quête de demander « actionne ce levier » ;
 *   - un GAIN DE MÉTIER optionnel.
 *
 * Sa disponibilité se décompose en deux : un délai (`cooldownSecondes`) et une PORTÉE
 * (`porteeRecharge`) qui dit à qui ce délai s'applique.
 */
#[ORM\Entity(repositoryClass: InteractionRepository::class)]
class Interaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $nom = '';

    #[ORM\Column(type: 'string', length: 32, enumType: TypeInteraction::class)]
    private TypeInteraction $type = TypeInteraction::ACTIONNER;

    /** Image posée sur la case (dossier public/img/interaction). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $skin = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $messageSucces = null;

    /** Points d'action consommés (0 = gratuit). */
    #[ORM\Column(type: 'integer')]
    private int $coutPa = 0;

    #[ORM\ManyToOne(targetEntity: Recompense::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Recompense $recompense = null;

    /** Effet scripté déclenché en plus de la récompense (whitelist QuestEffect). */
    #[ORM\Column(type: 'string', length: 64, nullable: true, enumType: QuestEffect::class)]
    private ?QuestEffect $effect = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $effectParams = null;

    /* --- Métier (le cas de la récolte : requis pour agir, et alimenté en retour) --- */

    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Metier $metier = null;

    #[ORM\Column(type: 'integer')]
    private int $niveauMetierMin = 0;

    #[ORM\Column(type: 'integer')]
    private int $experienceMetier = 0;

    /* --- Disponibilité --- */

    #[ORM\Column(type: 'integer')]
    private int $cooldownSecondes = 0;

    #[ORM\Column(type: 'string', length: 16, enumType: PorteeRecharge::class)]
    private PorteeRecharge $porteeRecharge = PorteeRecharge::JOUEUR;

    /** Utilisable une seule fois, jamais rechargée (dans la limite de la portée). */
    #[ORM\Column(type: 'boolean')]
    private bool $usageUnique = false;

    /**
     * Cette case propose-t-elle le choix « récolte mesurée / récolte intensive » ?
     *
     * Faux par défaut, donc toutes les cases déjà posées gardent EXACTEMENT leur
     * comportement : un mode envoyé sur une case qui ne le propose pas est refusé.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $recolteChoix = false;

    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    #[ORM\OneToMany(mappedBy: 'interaction', targetEntity: InteractionCondition::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $conditions;

    #[ORM\OneToMany(mappedBy: 'interaction', targetEntity: CarteCarreau::class)]
    private Collection $carteCarreaus;

    public function __construct()
    {
        $this->conditions = new ArrayCollection();
        $this->carteCarreaus = new ArrayCollection();
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

    public function getType(): TypeInteraction
    {
        return $this->type;
    }

    public function setType(TypeInteraction $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSkin(): ?string
    {
        return $this->skin;
    }

    public function setSkin(?string $skin): self
    {
        $this->skin = $skin;

        return $this;
    }

    public function getMessageSucces(): ?string
    {
        return $this->messageSucces;
    }

    public function setMessageSucces(?string $messageSucces): self
    {
        $this->messageSucces = $messageSucces;

        return $this;
    }

    public function getCoutPa(): int
    {
        return $this->coutPa;
    }

    public function setCoutPa(int $coutPa): self
    {
        $this->coutPa = max(0, $coutPa);

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

    public function getEffect(): ?QuestEffect
    {
        return $this->effect;
    }

    public function setEffect(?QuestEffect $effect): self
    {
        $this->effect = $effect;

        return $this;
    }

    public function getEffectParams(): ?array
    {
        return $this->effectParams;
    }

    public function setEffectParams(?array $effectParams): self
    {
        $this->effectParams = $effectParams;

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

    public function getNiveauMetierMin(): int
    {
        return $this->niveauMetierMin;
    }

    public function setNiveauMetierMin(int $niveauMetierMin): self
    {
        $this->niveauMetierMin = max(0, $niveauMetierMin);

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

    public function getCooldownSecondes(): int
    {
        return $this->cooldownSecondes;
    }

    public function setCooldownSecondes(int $cooldownSecondes): self
    {
        $this->cooldownSecondes = max(0, $cooldownSecondes);

        return $this;
    }

    public function getPorteeRecharge(): PorteeRecharge
    {
        return $this->porteeRecharge;
    }

    public function setPorteeRecharge(PorteeRecharge $porteeRecharge): self
    {
        $this->porteeRecharge = $porteeRecharge;

        return $this;
    }

    public function isUsageUnique(): bool
    {
        return $this->usageUnique;
    }

    public function setUsageUnique(bool $usageUnique): self
    {
        $this->usageUnique = $usageUnique;

        return $this;
    }

    public function isRecolteChoix(): bool
    {
        return $this->recolteChoix;
    }

    public function setRecolteChoix(bool $recolteChoix): self
    {
        $this->recolteChoix = $recolteChoix;

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

    /** @return Collection<int, InteractionCondition> */
    public function getConditions(): Collection
    {
        return $this->conditions;
    }

    public function addCondition(InteractionCondition $condition): self
    {
        if (!$this->conditions->contains($condition)) {
            $this->conditions[] = $condition;
            $condition->setInteraction($this);
        }

        return $this;
    }

    public function removeCondition(InteractionCondition $condition): self
    {
        $this->conditions->removeElement($condition);

        return $this;
    }

    /** @return Collection<int, CarteCarreau> */
    public function getCarteCarreaus(): Collection
    {
        return $this->carteCarreaus;
    }

    public function exigeUnMetier(): bool
    {
        return $this->metier !== null;
    }
}
