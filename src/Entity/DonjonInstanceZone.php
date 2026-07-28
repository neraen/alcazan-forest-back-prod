<?php

namespace App\Entity;

use App\Repository\DonjonInstanceZoneRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une zone TÉLÉGRAPHIÉE en attente de résolution = RUNTIME (jamais exporté dans le seed).
 *
 * C'est le seul mécanisme qui donne une horloge au combat : le boss annonce des cases,
 * elles frappent `resoudreAt`. Entre les deux, les joueurs ont le temps de bouger — c'est
 * précisément ce qui rend la position décisive.
 *
 * La résolution est PARESSEUSE, constatée au tick (DonjonCombatService) : aucune tâche
 * planifiée, donc aucune dérive entre l'horloge serveur et ce que voit le joueur.
 */
#[ORM\Entity(repositoryClass: DonjonInstanceZoneRepository::class)]
#[ORM\Index(name: 'idx_donjon_zone_instance', columns: ['instance_id', 'resolue'])]
class DonjonInstanceZone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DonjonInstance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonInstance $instance = null;

    #[ORM\Column(type: 'integer')]
    private int $carteId = 0;

    /** Cases visées : [{abscisse, ordonnee}, …] — le front les surligne. */
    #[ORM\Column(type: 'json')]
    private array $cases = [];

    #[ORM\Column(type: 'integer')]
    private int $degats = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $annonceeAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $resoudreAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $resolue = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $annonce = null;

    public function __construct()
    {
        $this->annonceeAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCarteId(): int
    {
        return $this->carteId;
    }

    public function setCarteId(int $carteId): self
    {
        $this->carteId = $carteId;

        return $this;
    }

    public function getCases(): array
    {
        return $this->cases;
    }

    public function setCases(array $cases): self
    {
        $this->cases = $cases;

        return $this;
    }

    public function getDegats(): int
    {
        return $this->degats;
    }

    public function setDegats(int $degats): self
    {
        $this->degats = $degats;

        return $this;
    }

    public function getAnnonceeAt(): \DateTimeImmutable
    {
        return $this->annonceeAt;
    }

    public function getResoudreAt(): ?\DateTimeImmutable
    {
        return $this->resoudreAt;
    }

    public function setResoudreAt(\DateTimeImmutable $resoudreAt): self
    {
        $this->resoudreAt = $resoudreAt;

        return $this;
    }

    public function isResolue(): bool
    {
        return $this->resolue;
    }

    public function setResolue(bool $resolue): self
    {
        $this->resolue = $resolue;

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

    public function couvre(int $abscisse, int $ordonnee): bool
    {
        foreach ($this->cases as $case) {
            if ((int)$case['abscisse'] === $abscisse && (int)$case['ordonnee'] === $ordonnee) {
                return true;
            }
        }

        return false;
    }

    public function estEchue(?\DateTimeImmutable $maintenant = null): bool
    {
        return !$this->resolue
            && $this->resoudreAt !== null
            && ($maintenant ?? new \DateTimeImmutable()) >= $this->resoudreAt;
    }
}
