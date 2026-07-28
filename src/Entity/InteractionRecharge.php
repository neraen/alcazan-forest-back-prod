<?php

namespace App\Entity;

use App\Repository\InteractionRechargeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * État de rechargement d'une case interactive = RUNTIME (jamais exporté dans le seed).
 *
 * `cle` porte la PORTÉE du cooldown sous forme de chaîne (`monde`, `user:38`,
 * `instance:12`) plutôt que deux colonnes nullables. Raison : en MySQL, un index UNIQUE
 * n'empêche pas les doublons quand une colonne est NULL — deux joueurs auraient pu créer
 * deux recharges « monde » concurrentes. Avec une clé textuelle, l'unicité
 * (carte_carreau, cle) est réellement garantie par la base.
 */
#[ORM\Entity(repositoryClass: InteractionRechargeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_interaction_recharge', columns: ['carte_carreau_id', 'cle'])]
class InteractionRecharge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CarteCarreau::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CarteCarreau $carteCarreau = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $cle = 'monde';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $utiliseeAt;

    /** Null = jamais rechargée (usage unique). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disponibleAt = null;

    public function __construct()
    {
        $this->utiliseeAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCarteCarreau(): ?CarteCarreau
    {
        return $this->carteCarreau;
    }

    public function setCarteCarreau(?CarteCarreau $carteCarreau): self
    {
        $this->carteCarreau = $carteCarreau;

        return $this;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function setCle(string $cle): self
    {
        $this->cle = $cle;

        return $this;
    }

    public function getUtiliseeAt(): \DateTimeImmutable
    {
        return $this->utiliseeAt;
    }

    public function setUtiliseeAt(\DateTimeImmutable $utiliseeAt): self
    {
        $this->utiliseeAt = $utiliseeAt;

        return $this;
    }

    public function getDisponibleAt(): ?\DateTimeImmutable
    {
        return $this->disponibleAt;
    }

    public function setDisponibleAt(?\DateTimeImmutable $disponibleAt): self
    {
        $this->disponibleAt = $disponibleAt;

        return $this;
    }

    public function estDisponible(?\DateTimeImmutable $maintenant = null): bool
    {
        if ($this->disponibleAt === null) {
            return false; // usage unique déjà consommé
        }

        return ($maintenant ?? new \DateTimeImmutable()) >= $this->disponibleAt;
    }
}
