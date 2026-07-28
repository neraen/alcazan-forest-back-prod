<?php

namespace App\Entity;

use App\Repository\DonjonVerrouRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Verrou quotidien d'un joueur sur un donjon = RUNTIME (jamais exporté dans le seed).
 *
 * Modèle retenu le 25/07/2026 : le verrou se pose à la PREMIÈRE entrée du jour et
 * reste LIÉ à l'instance obtenue. Tant qu'il n'est pas périmé, le joueur peut revenir
 * autant qu'il veut dans SON instance, mais ne peut pas en obtenir une neuve — ce qui
 * règle gratuitement déconnexions, wipes et pauses, ingérables avec un 24 h glissant.
 *
 * `jourReset` est le « jour de donjon » (cf. DonjonInstanceService::jourDeDonjon) et
 * non la date civile : avec un reset à 5 h, une session de 2 h du matin compte pour la
 * veille. L'unicité (user, donjon, jourReset) est ce qui matérialise le « une fois par jour ».
 */
#[ORM\Entity(repositoryClass: DonjonVerrouRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_donjon_verrou_jour', columns: ['user_id', 'donjon_id', 'jour_reset'])]
class DonjonVerrou
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Donjon::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Donjon $donjon = null;

    #[ORM\ManyToOne(targetEntity: DonjonInstance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonInstance $instance = null;

    /** Jour de donjon (date), pas la date civile — cf. docblock de classe. */
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $jourReset = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getDonjon(): ?Donjon
    {
        return $this->donjon;
    }

    public function setDonjon(?Donjon $donjon): self
    {
        $this->donjon = $donjon;

        return $this;
    }

    public function getInstance(): ?DonjonInstance
    {
        return $this->instance;
    }

    public function setInstance(?DonjonInstance $instance): self
    {
        $this->instance = $instance;

        return $this;
    }

    public function getJourReset(): ?\DateTimeImmutable
    {
        return $this->jourReset;
    }

    public function setJourReset(\DateTimeImmutable $jourReset): self
    {
        $this->jourReset = $jourReset;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
