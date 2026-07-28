<?php

namespace App\Entity;

use App\Enum\StatutGroupeDonjon;
use App\Repository\DonjonGroupeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Groupe ÉPHÉMÈRE formé devant la porte d'un donjon = RUNTIME (jamais exporté dans le seed).
 *
 * Choix assumé du 25/07/2026 : pas de système de groupe global dans le jeu. Un groupe naît
 * à la porte, sert à composer une instance, et meurt au lancement. Rien ne le référence
 * ensuite : dans le donjon, c'est DonjonInstanceMembre qui fait foi.
 *
 * Il ne consomme AUCUN verrou : tant que le meneur n'a pas lancé, personne n'a « fait »
 * le donjon. C'est pour ça que le lobby est une table à part et non un statut d'instance.
 *
 * Toute mutation passe par DonjonGroupeService.
 */
#[ORM\Entity(repositoryClass: DonjonGroupeRepository::class)]
#[ORM\Index(name: 'idx_donjon_groupe_statut', columns: ['statut'])]
class DonjonGroupe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Donjon::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Donjon $donjon = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $leader = null;

    #[ORM\Column(type: 'string', length: 32, enumType: StatutGroupeDonjon::class)]
    private StatutGroupeDonjon $statut = StatutGroupeDonjon::OUVERT;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Un lobby oublié devant la porte ne doit pas y rester la journée. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expireAt;

    #[ORM\OneToMany(mappedBy: 'groupe', targetEntity: DonjonGroupeMembre::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $membres;

    public function __construct(int $dureeVieMinutes = 15)
    {
        $this->membres = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->expireAt = $this->createdAt->modify("+{$dureeVieMinutes} minutes");
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

    public function getStatut(): StatutGroupeDonjon
    {
        return $this->statut;
    }

    public function setStatut(StatutGroupeDonjon $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpireAt(): \DateTimeImmutable
    {
        return $this->expireAt;
    }

    /** @return Collection<int, DonjonGroupeMembre> */
    public function getMembres(): Collection
    {
        return $this->membres;
    }

    public function addMembre(DonjonGroupeMembre $membre): self
    {
        if (!$this->membres->contains($membre)) {
            $this->membres[] = $membre;
            $membre->setGroupe($this);
        }

        return $this;
    }

    public function removeMembre(DonjonGroupeMembre $membre): self
    {
        $this->membres->removeElement($membre);

        return $this;
    }

    public function membrePour(User $user): ?DonjonGroupeMembre
    {
        foreach ($this->membres as $membre) {
            if ($membre->getUser()?->getId() === $user->getId()) {
                return $membre;
            }
        }

        return null;
    }

    /** @return User[] membres autres que le meneur, dans l'ordre d'inscription */
    public function compagnons(): array
    {
        $compagnons = [];
        foreach ($this->membres as $membre) {
            if ($membre->getUser()?->getId() !== $this->leader?->getId()) {
                $compagnons[] = $membre->getUser();
            }
        }

        return $compagnons;
    }

    public function estComplet(): bool
    {
        return $this->membres->count() >= ($this->donjon?->getTailleGroupeMax() ?? 1);
    }

    public function estPerime(?\DateTimeImmutable $maintenant = null): bool
    {
        return ($maintenant ?? new \DateTimeImmutable()) >= $this->expireAt;
    }
}
