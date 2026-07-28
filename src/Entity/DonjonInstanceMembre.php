<?php

namespace App\Entity;

use App\Repository\DonjonInstanceMembreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appartenance d'un joueur à une instance = RUNTIME (jamais exporté dans le seed).
 *
 * Ne porte PAS de position : la position du joueur reste `user.map_id/case_*`, comme
 * partout ailleurs dans le jeu. C'est l'écriture dans `carte_carreau.joueur_id` qui est
 * sautée en instance (cette colonne est un OneToOne global, donc incompatible avec
 * plusieurs groupes sur la même carte) ; DonjonMapView reconstruit l'occupation à partir
 * des membres présents.
 */
#[ORM\Entity(repositoryClass: DonjonInstanceMembreRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_donjon_membre', columns: ['instance_id', 'user_id'])]
class DonjonInstanceMembre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DonjonInstance::class, inversedBy: 'membres')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DonjonInstance $instance = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /** Faux quand le joueur est ressorti : il peut revenir, l'instance reste la sienne. */
    #[ORM\Column(type: 'boolean')]
    private bool $present = true;

    /**
     * Menace accumulée face au boss. Le boss frappe la plus GROSSE menace, pas le dernier
     * attaquant : c'est ce seul choix qui fait exister le rôle de tank, donc la coordination.
     */
    #[ORM\Column(type: 'integer')]
    private int $menace = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function setPresent(bool $present): self
    {
        $this->present = $present;

        return $this;
    }

    public function getMenace(): int
    {
        return $this->menace;
    }

    public function setMenace(int $menace): self
    {
        $this->menace = max(0, $menace);

        return $this;
    }

    public function ajouterMenace(int $menace): self
    {
        return $this->setMenace($this->menace + $menace);
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
