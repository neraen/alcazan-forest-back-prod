<?php

namespace App\Entity;

use App\Enum\TypeCible;
use App\Enum\TypeEvenement;
use App\Repository\EvenementJeuRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fait de partie horodaté : qui a fait quoi, à qui, sur quoi, pour combien.
 *
 * Le journal répond à « QU'EST-CE QUI S'EST PASSÉ » — `joueur_compteur` et `joueur_cumul`
 * répondent à « combien ». Ce sont trois tables et trois questions, jamais trois vérités
 * sur la même : le journal n'est PAS un grand livre comptable, la vérité sur ce que
 * possède un joueur reste son inventaire, dont `SacService` est l'unique point de mutation.
 *
 * L'entité n'est JAMAIS persistée par l'ORM. `JournalService` écrit en SQL natif et lit
 * en tableaux : le mapping existe pour que `doctrine:migrations:diff` connaisse la table
 * et que `schema:validate` reste vert, pas pour hydrater des objets. Une table d'archive
 * qu'on ne lit que par pages filtrées n'a rien à gagner à passer par l'unité de travail.
 *
 * Table de RUNTIME joueur : elle est dans la liste noire de `content-dump.sh`.
 * Écrite UNIQUEMENT par JournalService.
 */
#[ORM\Entity(repositoryClass: EvenementJeuRepository::class)]
#[ORM\Table(name: 'evenement_jeu')]
#[ORM\Index(name: 'idx_evenement_acteur', columns: ['acteur_id', 'cree_le'])]
#[ORM\Index(name: 'idx_evenement_cible_user', columns: ['cible_user_id', 'cree_le'])]
#[ORM\Index(name: 'idx_evenement_type', columns: ['type', 'cree_le'])]
#[ORM\Index(name: 'idx_evenement_date', columns: ['cree_le'])]
class EvenementJeu
{
    /**
     * BIGINT : c'est la seule table du projet dont la croissance n'est pas bornée par le
     * contenu. Le plafond d'un INT n'est pas atteignable ici, mais élargir une clé primaire
     * auto-incrémentée après coup est une migration bloquante sur une grosse table, alors
     * que l'anticipation coûte quatre octets par ligne.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 40, enumType: TypeEvenement::class)]
    private TypeEvenement $type;

    /** Qui a agi. NULL quand le fait n'a pas d'auteur (mort causée par l'environnement). */
    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $acteur = null;

    /**
     * Qui a subi. Une COLONNE et pas une clé du contexte JSON : la requête n°1 de
     * l'administration est « la fiche du joueur X », c'est-à-dire tout ce qu'il a fait ET
     * subi. En JSON, cette requête deviendrait un scan complet avec JSON_EXTRACT sur
     * précisément la table qu'on ne peut pas scanner ; en colonne indexée, c'est deux
     * parcours d'index.
     */
    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $cibleUser = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: TypeCible::class)]
    private ?TypeCible $cibleType = null;

    /** Entier nu, sans FK : voir `TypeCible`. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cibleId = null;

    /** La chose comptée : 1 monstre, 12 minerais, 340 points d'XP, un numéro de niveau. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $quantite = 0;

    /**
     * L'or déplacé par ce fait.
     *
     * Une colonne à part et non une clé du contexte, parce que l'or bouge EN MÊME TEMPS
     * qu'autre chose (un échange = des objets + de l'or) et qu'on veut pouvoir sommer les
     * flux monétaires sans ouvrir le JSON.
     *
     * ⚠️ Le nom est `montant_or` et jamais `or` : `OR` est un mot réservé MySQL. Même
     * famille de piège que `donjon_salle.condition`, mais évitée par le nommage plutôt que
     * par des backticks — un nom qui doit être échappé finit toujours par casser un INSERT.
     */
    #[ORM\Column(name: 'montant_or', type: 'integer', options: ['default' => 0])]
    private int $montantOr = 0;

    /** Ce qui ne mérite pas de colonne : items figés, cause de mort, détails de rendu. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contexte = null;

    #[ORM\Column(name: 'cree_le', type: 'datetime_immutable')]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): TypeEvenement
    {
        return $this->type;
    }

    public function setType(TypeEvenement $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getActeur(): ?User
    {
        return $this->acteur;
    }

    public function setActeur(?User $acteur): self
    {
        $this->acteur = $acteur;

        return $this;
    }

    public function getCibleUser(): ?User
    {
        return $this->cibleUser;
    }

    public function setCibleUser(?User $cibleUser): self
    {
        $this->cibleUser = $cibleUser;

        return $this;
    }

    public function getCibleType(): ?TypeCible
    {
        return $this->cibleType;
    }

    public function setCibleType(?TypeCible $cibleType): self
    {
        $this->cibleType = $cibleType;

        return $this;
    }

    public function getCibleId(): ?int
    {
        return $this->cibleId;
    }

    public function setCibleId(?int $cibleId): self
    {
        $this->cibleId = $cibleId;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getMontantOr(): int
    {
        return $this->montantOr;
    }

    public function setMontantOr(int $montantOr): self
    {
        $this->montantOr = $montantOr;

        return $this;
    }

    public function getContexte(): ?array
    {
        return $this->contexte;
    }

    public function setContexte(?array $contexte): self
    {
        $this->contexte = $contexte;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $creeLe): self
    {
        $this->creeLe = $creeLe;

        return $this;
    }
}
