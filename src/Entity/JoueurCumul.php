<?php

namespace App\Entity;

use App\Enum\TypeCumul;
use App\Repository\JoueurCumulRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un total de partie sans cible : XP totale, monstres vaincus, morts…
 *
 * Table SŒUR de `joueur_compteur`, pas concurrente : celle-ci répond à « combien au total »,
 * l'autre à « combien de fois, par cible ». Le détail de la distinction et la raison pour
 * laquelle elle ne pouvait pas tenir dans le modèle existant sont dans `TypeCumul`.
 *
 * `valeur` est un BIGINT et non un INT : l'XP totale d'un personnage de haut niveau se compte
 * en centaines de millions une fois la courbe (`10000 × 1,01^n`, cap 200) parcourue, et
 * élargir la colonne après coup sur une table indexée est une migration bloquante.
 *
 * Table de RUNTIME joueur : elle est dans la liste noire de `content-dump.sh`.
 * Mutée UNIQUEMENT par CumulJoueurService.
 */
#[ORM\Entity(repositoryClass: JoueurCumulRepository::class)]
#[ORM\Table(name: 'joueur_cumul')]
#[ORM\UniqueConstraint(name: 'uniq_joueur_cumul', columns: ['user_id', 'cle'])]
#[ORM\Index(name: 'idx_joueur_cumul_classement', columns: ['cle', 'valeur'])]
class JoueurCumul
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 40, enumType: TypeCumul::class)]
    private TypeCumul $cle;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $valeur = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $majAt;

    public function __construct()
    {
        $this->majAt = new \DateTimeImmutable();
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

    public function getCle(): TypeCumul
    {
        return $this->cle;
    }

    public function setCle(TypeCumul $cle): self
    {
        $this->cle = $cle;

        return $this;
    }

    public function getValeur(): int
    {
        return $this->valeur;
    }

    public function setValeur(int $valeur): self
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getMajAt(): \DateTimeImmutable
    {
        return $this->majAt;
    }

    public function setMajAt(\DateTimeImmutable $majAt): self
    {
        $this->majAt = $majAt;

        return $this;
    }
}
