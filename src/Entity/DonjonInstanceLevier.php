<?php

namespace App\Entity;

use App\Repository\DonjonInstanceLevierRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un levier actionné dans une instance = RUNTIME (jamais exporté dans le seed).
 *
 * L'énigme consiste à faire actionner N leviers par des joueurs DIFFÉRENTS dans une
 * fenêtre de temps : c'est la seule mécanique qui oblige le groupe à se répartir dans
 * la salle plutôt qu'à empiler ses dégâts.
 *
 * Le levier lui-même est une case action ordinaire (SCRIPTED_EFFECT / actionner_levier) :
 * on réutilise la machinerie des quêtes plutôt que d'inventer un type de case.
 */
#[ORM\Entity(repositoryClass: DonjonInstanceLevierRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_donjon_levier', columns: ['instance_id', 'carte_carreau_id'])]
class DonjonInstanceLevier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DonjonInstance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonInstance $instance = null;

    #[ORM\Column(type: 'integer')]
    private int $carteCarreauId = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actionnePar = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $actionneAt;

    public function __construct()
    {
        $this->actionneAt = new \DateTimeImmutable();
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

    public function getCarteCarreauId(): int
    {
        return $this->carteCarreauId;
    }

    public function setCarteCarreauId(int $carteCarreauId): self
    {
        $this->carteCarreauId = $carteCarreauId;

        return $this;
    }

    public function getActionnePar(): ?User
    {
        return $this->actionnePar;
    }

    public function setActionnePar(?User $actionnePar): self
    {
        $this->actionnePar = $actionnePar;

        return $this;
    }

    public function getActionneAt(): \DateTimeImmutable
    {
        return $this->actionneAt;
    }

    public function setActionneAt(\DateTimeImmutable $actionneAt): self
    {
        $this->actionneAt = $actionneAt;

        return $this;
    }
}
