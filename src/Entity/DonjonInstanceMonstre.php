<?php

namespace App\Entity;

use App\Repository\DonjonInstanceMonstreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un renfort (add) vivant DANS une instance = RUNTIME (jamais exporté dans le seed).
 *
 * Les monstres du monde ouvert vivent dans `monstre_carreau`, attaché au décor : comme
 * `carte_carreau.joueur_id`, cette table ne peut pas porter plusieurs groupes à la fois.
 * Les renforts d'instance ont donc leur propre table, et leur position n'est PAS écrite
 * dans le décor — DonjonMapView les réinjecte, exactement comme les membres du groupe.
 */
#[ORM\Entity(repositoryClass: DonjonInstanceMonstreRepository::class)]
#[ORM\Index(name: 'idx_donjon_monstre_instance', columns: ['instance_id', 'vivant'])]
class DonjonInstanceMonstre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DonjonInstance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonInstance $instance = null;

    #[ORM\ManyToOne(targetEntity: Monstre::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Monstre $monstre = null;

    #[ORM\Column(type: 'integer')]
    private int $carteId = 0;

    #[ORM\Column(type: 'integer')]
    private int $abscisse = 0;

    #[ORM\Column(type: 'integer')]
    private int $ordonnee = 0;

    #[ORM\Column(type: 'integer')]
    private int $currentLife = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $vivant = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $apparuAt;

    public function __construct()
    {
        $this->apparuAt = new \DateTimeImmutable();
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

    public function getMonstre(): ?Monstre
    {
        return $this->monstre;
    }

    public function setMonstre(?Monstre $monstre): self
    {
        $this->monstre = $monstre;

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

    public function getAbscisse(): int
    {
        return $this->abscisse;
    }

    public function setAbscisse(int $abscisse): self
    {
        $this->abscisse = $abscisse;

        return $this;
    }

    public function getOrdonnee(): int
    {
        return $this->ordonnee;
    }

    public function setOrdonnee(int $ordonnee): self
    {
        $this->ordonnee = $ordonnee;

        return $this;
    }

    public function getCurrentLife(): int
    {
        return $this->currentLife;
    }

    public function setCurrentLife(int $currentLife): self
    {
        $this->currentLife = max(0, $currentLife);
        if ($this->currentLife === 0) {
            $this->vivant = false;
        }

        return $this;
    }

    public function isVivant(): bool
    {
        return $this->vivant;
    }

    public function setVivant(bool $vivant): self
    {
        $this->vivant = $vivant;

        return $this;
    }

    public function getApparuAt(): \DateTimeImmutable
    {
        return $this->apparuAt;
    }
}
