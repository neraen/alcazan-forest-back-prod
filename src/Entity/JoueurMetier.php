<?php

namespace App\Entity;

use App\Repository\JoueurMetierRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progression d'un joueur dans un métier = données JOUEUR (exclues du seed).
 *
 * ⚠️ **Une ligne signifie « métier APPRIS »**, et non « déjà pratiqué ». La ligne ne se
 * crée plus toute seule au premier gain d'expérience : elle naît d'un acte explicite chez
 * un maître de métier. C'est la condition sans laquelle le plafond « 2 récolte / 3 craft »
 * ne serait pas applicable — on ne plafonne pas ce qui s'auto-crée.
 *
 * Pas de ligne = métier non appris = niveau 0.
 */
#[ORM\Entity(repositoryClass: JoueurMetierRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_joueur_metier', columns: ['user_id', 'metier_id'])]
class JoueurMetier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Metier $metier = null;

    #[ORM\Column(type: 'integer')]
    private int $niveau = 1;

    #[ORM\Column(type: 'integer')]
    private int $experience = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $apprisAt;

    public function __construct()
    {
        $this->apprisAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

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

    public function getNiveau(): int
    {
        return $this->niveau;
    }

    public function setNiveau(int $niveau): self
    {
        $this->niveau = max(1, $niveau);

        return $this;
    }

    public function getExperience(): int
    {
        return $this->experience;
    }

    public function setExperience(int $experience): self
    {
        $this->experience = max(0, $experience);

        return $this;
    }

    public function getApprisAt(): \DateTimeImmutable
    {
        return $this->apprisAt;
    }

    public function setApprisAt(\DateTimeImmutable $apprisAt): self
    {
        $this->apprisAt = $apprisAt;

        return $this;
    }
}
