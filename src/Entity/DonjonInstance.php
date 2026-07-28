<?php

namespace App\Entity;

use App\Enum\StatutInstanceDonjon;
use App\Repository\DonjonInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie de donjon = état de RUNTIME (jamais exporté dans le seed).
 *
 * C'est ici que vivent les valeurs qui, jusqu'au 25/07/2026, étaient partagées par
 * tout le serveur : la vie du boss en particulier (`boss.actual_life` reste la
 * valeur des boss de plein air, hors donjon).
 *
 * Toute mutation passe par DonjonInstanceService, sous verrou pessimiste.
 */
#[ORM\Entity(repositoryClass: DonjonInstanceRepository::class)]
#[ORM\Index(name: 'idx_donjon_instance_statut', columns: ['statut'])]
class DonjonInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Donjon::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Donjon $donjon = null;

    /** Créateur de l'instance (porteur du verrou d'origine). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $leader = null;

    #[ORM\Column(type: 'string', length: 32, enumType: StatutInstanceDonjon::class)]
    private StatutInstanceDonjon $statut = StatutInstanceDonjon::EN_COURS;

    /** Vie courante du boss DANS cette instance (null tant qu'il n'a pas été engagé). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $bossCurrentLife = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Null si le donjon n'impose pas de durée max. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expireAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** Premier coup porté au boss : origine du chronomètre d'enrage. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $combatDebutAt = null;

    /**
     * Dernier passage du tick de combat. Le tick est PARESSEUX : il est joué au fil des
     * requêtes des joueurs de l'instance, jamais par une tâche planifiée (le scheduler
     * tourne à la minute, beaucoup trop grossier pour une rencontre).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dernierTickAt = null;

    /** Déclenchements déjà joués : {mecaniqueId: timestamp} — porte les cooldowns. */
    #[ORM\Column(type: 'json')]
    private array $mecaniquesJouees = [];

    #[ORM\OneToMany(mappedBy: 'instance', targetEntity: DonjonInstanceMembre::class, cascade: ['persist'])]
    private Collection $membres;

    public function __construct()
    {
        $this->membres = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function getLeader(): ?User
    {
        return $this->leader;
    }

    public function setLeader(?User $leader): self
    {
        $this->leader = $leader;

        return $this;
    }

    public function getStatut(): StatutInstanceDonjon
    {
        return $this->statut;
    }

    public function setStatut(StatutInstanceDonjon $statut): self
    {
        $this->statut = $statut;
        if ($statut->estTerminal() && $this->closedAt === null) {
            $this->closedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getBossCurrentLife(): ?int
    {
        return $this->bossCurrentLife;
    }

    public function setBossCurrentLife(?int $bossCurrentLife): self
    {
        $this->bossCurrentLife = $bossCurrentLife;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpireAt(): ?\DateTimeImmutable
    {
        return $this->expireAt;
    }

    public function setExpireAt(?\DateTimeImmutable $expireAt): self
    {
        $this->expireAt = $expireAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCombatDebutAt(): ?\DateTimeImmutable
    {
        return $this->combatDebutAt;
    }

    public function setCombatDebutAt(?\DateTimeImmutable $combatDebutAt): self
    {
        $this->combatDebutAt = $combatDebutAt;

        return $this;
    }

    public function getDernierTickAt(): ?\DateTimeImmutable
    {
        return $this->dernierTickAt;
    }

    public function setDernierTickAt(?\DateTimeImmutable $dernierTickAt): self
    {
        $this->dernierTickAt = $dernierTickAt;

        return $this;
    }

    public function getMecaniquesJouees(): array
    {
        return $this->mecaniquesJouees;
    }

    /** Dernier déclenchement de cette mécanique, ou null si jamais jouée. */
    public function dernierDeclenchement(int $mecaniqueId): ?\DateTimeImmutable
    {
        $timestamp = $this->mecaniquesJouees[(string)$mecaniqueId] ?? null;

        return $timestamp === null ? null : new \DateTimeImmutable('@' . $timestamp);
    }

    public function marquerDeclenchement(int $mecaniqueId, \DateTimeImmutable $quand): self
    {
        // Réaffectation complète : Doctrine ne détecte pas la mutation d'un tableau JSON en place.
        $this->mecaniquesJouees = $this->mecaniquesJouees + [];
        $this->mecaniquesJouees[(string)$mecaniqueId] = $quand->getTimestamp();

        return $this;
    }

    /** Le boss a-t-il déjà été engagé dans cette instance ? */
    public function combatEngage(): bool
    {
        return $this->combatDebutAt !== null;
    }

    /** @return Collection<int, DonjonInstanceMembre> */
    public function getMembres(): Collection
    {
        return $this->membres;
    }

    public function addMembre(DonjonInstanceMembre $membre): self
    {
        if (!$this->membres->contains($membre)) {
            $this->membres[] = $membre;
            $membre->setInstance($this);
        }

        return $this;
    }

    /** La carte fait-elle partie du donjon de cette instance ? */
    public function contientCarte(?int $carteId): bool
    {
        return $carteId !== null && in_array($carteId, $this->donjon?->getCarteIds() ?? [], true);
    }

    public function estPerimee(?\DateTimeImmutable $maintenant = null): bool
    {
        return $this->expireAt !== null && ($maintenant ?? new \DateTimeImmutable()) >= $this->expireAt;
    }

    /** @return DonjonInstanceMembre[] membres encore à l'intérieur */
    public function membresPresents(): array
    {
        return array_values(array_filter(
            $this->membres->toArray(),
            fn (DonjonInstanceMembre $membre) => $membre->isPresent()
        ));
    }

    public function membrePour(User $user): ?DonjonInstanceMembre
    {
        foreach ($this->membres as $membre) {
            if ($membre->getUser()?->getId() === $user->getId()) {
                return $membre;
            }
        }

        return null;
    }
}
