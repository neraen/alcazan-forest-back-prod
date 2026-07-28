<?php

namespace App\Entity;

use App\Enum\TypeCompteur;
use App\Repository\CompteurJoueurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Combien de fois un joueur a fait quelque chose de comptable : tuer tel monstre,
 * fabriquer telle recette, récolter telle ressource.
 *
 * UNE table générique plutôt qu'une par événement (`user_monstre`, `user_recette`…) :
 * les trois compteurs se lisent et s'écrivent exactement pareil, et une quête qui
 * demandera demain « visiter 5 donjons » n'aura besoin d'aucune migration.
 *
 * La cible est un ENTIER NU, sans clé étrangère, et c'est délibéré : le type dit
 * déjà vers quelle table elle pointe, et une FK par type ramènerait les colonnes
 * nullables qu'on cherche justement à éviter (cf. `interaction_recharge.cle`).
 * Contrepartie assumée : supprimer un monstre laisse des lignes orphelines, qui
 * ne sont jamais lues puisque plus aucune action de quête ne peut le cibler.
 *
 * Table de RUNTIME joueur : elle est dans la liste noire de `content-dump.sh`.
 * Muté UNIQUEMENT par CompteurJoueurService.
 */
#[ORM\Entity(repositoryClass: CompteurJoueurRepository::class)]
#[ORM\Table(name: 'joueur_compteur')]
#[ORM\UniqueConstraint(name: 'uniq_joueur_compteur', columns: ['user_id', 'type', 'cible_id'])]
class CompteurJoueur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 32, enumType: TypeCompteur::class)]
    private TypeCompteur $type;

    #[ORM\Column(type: 'integer')]
    private int $cibleId = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
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

    public function getType(): TypeCompteur
    {
        return $this->type;
    }

    public function setType(TypeCompteur $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCibleId(): int
    {
        return $this->cibleId;
    }

    public function setCibleId(int $cibleId): self
    {
        $this->cibleId = $cibleId;

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
