<?php

namespace App\Entity;

use App\Repository\DonjonInstanceSalleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * État d'une salle DANS une instance = RUNTIME (jamais exporté dans le seed).
 *
 * Porte deux choses, chacune pour une bonne raison :
 *  - `peuplee` : la population de la salle n'apparaît qu'UNE fois par expédition. Sans ce
 *    drapeau, faire un aller-retour repeuplerait la salle indéfiniment (ferme à XP).
 *  - `ouverte` : une porte franchie le reste. On ne refait pas l'énigme à chaque passage,
 *    et surtout un joueur qui revient sur ses pas n'est jamais enfermé derrière elle.
 */
#[ORM\Entity(repositoryClass: DonjonInstanceSalleRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_donjon_instance_salle', columns: ['instance_id', 'salle_id'])]
class DonjonInstanceSalle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DonjonInstance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonInstance $instance = null;

    #[ORM\ManyToOne(targetEntity: DonjonSalle::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonSalle $salle = null;

    #[ORM\Column(type: 'boolean')]
    private bool $peuplee = false;

    #[ORM\Column(type: 'boolean')]
    private bool $ouverte = false;

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

    public function getSalle(): ?DonjonSalle
    {
        return $this->salle;
    }

    public function setSalle(?DonjonSalle $salle): self
    {
        $this->salle = $salle;

        return $this;
    }

    public function isPeuplee(): bool
    {
        return $this->peuplee;
    }

    public function setPeuplee(bool $peuplee): self
    {
        $this->peuplee = $peuplee;

        return $this;
    }

    public function isOuverte(): bool
    {
        return $this->ouverte;
    }

    public function setOuverte(bool $ouverte): self
    {
        $this->ouverte = $ouverte;

        return $this;
    }
}
