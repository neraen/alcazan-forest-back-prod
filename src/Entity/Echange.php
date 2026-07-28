<?php

namespace App\Entity;

use App\Enum\StatutEchange;
use App\Repository\EchangeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Session d'échange entre deux joueurs. La `version` est incrémentée à CHAQUE modification
 * valide (machine à états : EchangeService) ; le client renvoie la version qu'il connaît et
 * toute divergence vaut 409. Les sessions terminées restent en base (audit minimal).
 */
#[ORM\Entity(repositoryClass: EchangeRepository::class)]
#[ORM\Index(name: 'idx_echange_statut', columns: ['statut'])]
class Echange
{
    // Durée de vie d'une session, repoussée à chaque action valide.
    public const DUREE_VIE = '+5 minutes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // joueurUn = l'initiateur de l'invitation, joueurDeux = le destinataire.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $joueurUn;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $joueurDeux;

    #[ORM\Column(type: 'string', length: 20, enumType: StatutEchange::class)]
    private StatutEchange $statut = StatutEchange::EN_ATTENTE;

    #[ORM\Column(type: 'integer')]
    private int $orJoueurUn = 0;

    #[ORM\Column(type: 'integer')]
    private int $orJoueurDeux = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $confirmeJoueurUn = false;

    #[ORM\Column(type: 'boolean')]
    private bool $confirmeJoueurDeux = false;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $annulePar = null;

    /** @var Collection<int, EchangeLigne> */
    #[ORM\OneToMany(mappedBy: 'echange', targetEntity: EchangeLigne::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(self::DUREE_VIE);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueurUn(): User
    {
        return $this->joueurUn;
    }

    public function setJoueurUn(User $joueurUn): self
    {
        $this->joueurUn = $joueurUn;

        return $this;
    }

    public function getJoueurDeux(): User
    {
        return $this->joueurDeux;
    }

    public function setJoueurDeux(User $joueurDeux): self
    {
        $this->joueurDeux = $joueurDeux;

        return $this;
    }

    public function estParticipant(User $user): bool
    {
        return $this->joueurUn->getId() === $user->getId()
            || $this->joueurDeux->getId() === $user->getId();
    }

    public function getPartenaire(User $user): User
    {
        return $this->joueurUn->getId() === $user->getId() ? $this->joueurDeux : $this->joueurUn;
    }

    public function getStatut(): StatutEchange
    {
        return $this->statut;
    }

    public function setStatut(StatutEchange $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getOrPropose(User $user): int
    {
        return $this->joueurUn->getId() === $user->getId() ? $this->orJoueurUn : $this->orJoueurDeux;
    }

    public function setOrPropose(User $user, int $montant): self
    {
        if ($this->joueurUn->getId() === $user->getId()) {
            $this->orJoueurUn = $montant;
        } else {
            $this->orJoueurDeux = $montant;
        }

        return $this;
    }

    public function estConfirme(User $user): bool
    {
        return $this->joueurUn->getId() === $user->getId() ? $this->confirmeJoueurUn : $this->confirmeJoueurDeux;
    }

    public function setConfirme(User $user, bool $confirme): self
    {
        if ($this->joueurUn->getId() === $user->getId()) {
            $this->confirmeJoueurUn = $confirme;
        } else {
            $this->confirmeJoueurDeux = $confirme;
        }

        return $this;
    }

    public function lesDeuxOntConfirme(): bool
    {
        return $this->confirmeJoueurUn && $this->confirmeJoueurDeux;
    }

    public function invaliderConfirmations(): self
    {
        $this->confirmeJoueurUn = false;
        $this->confirmeJoueurDeux = false;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** Toute modification valide : version +1, updatedAt, expiration repoussée. */
    public function toucher(): self
    {
        ++$this->version;
        $this->updatedAt = new \DateTimeImmutable();
        if (!$this->statut->estTerminal()) {
            $this->expiresAt = new \DateTimeImmutable(self::DUREE_VIE);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function estExpire(): bool
    {
        return !$this->statut->estTerminal() && $this->expiresAt < new \DateTimeImmutable();
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getAnnulePar(): ?User
    {
        return $this->annulePar;
    }

    public function setAnnulePar(?User $annulePar): self
    {
        $this->annulePar = $annulePar;

        return $this;
    }

    /** @return Collection<int, EchangeLigne> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    /** @return EchangeLigne[] */
    public function getLignesDe(User $user): array
    {
        return array_values(array_filter(
            $this->lignes->toArray(),
            fn (EchangeLigne $ligne) => $ligne->getProprietaire()->getId() === $user->getId()
        ));
    }

    public function addLigne(EchangeLigne $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setEchange($this);
        }

        return $this;
    }

    public function removeLigne(EchangeLigne $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }
}
