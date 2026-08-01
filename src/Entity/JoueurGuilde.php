<?php

namespace App\Entity;

use App\Enum\GradeGuilde;
use App\Enum\StatutGuilde;
use App\Repository\JoueurGuildeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'appartenance d'un joueur à une guilde — candidature comprise.
 *
 * C'est désormais la SEULE vérité sur « qui est dans quelle guilde ». `user.guilde_id`
 * existait en parallèle et n'était écrite par aucun code : c'est ce qui faisait que rejoindre
 * une guilde n'avait aucun effet visible (l'adhésion écrivait ici, tout l'affichage lisait
 * là-bas). La colonne est supprimée avec ce lot, après remontée de ses données.
 *
 * **Un joueur = AU PLUS une ligne**, garantie par l'index UNIQUE `(user_id)` : il est
 * candidat quelque part ou membre quelque part, jamais les deux, jamais dans deux guildes.
 * Voir `StatutGuilde` pour le raisonnement.
 *
 * Table de RUNTIME joueur : elle est dans la liste noire de `content-dump.sh`.
 * Mutée UNIQUEMENT par GuildeService.
 */
#[ORM\Entity(repositoryClass: JoueurGuildeRepository::class)]
#[ORM\Table(name: 'joueur_guilde')]
#[ORM\UniqueConstraint(name: 'uniq_joueur_guilde_user', columns: ['user_id'])]
class JoueurGuilde
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'joueurGuildes')]
    private ?User $user = null;

    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: Guilde::class, inversedBy: 'joueurGuildes')]
    private ?Guilde $guilde = null;

    #[ORM\Column(type: 'string', length: 20, enumType: GradeGuilde::class)]
    private GradeGuilde $grade = GradeGuilde::RECRUE;

    #[ORM\Column(type: 'string', length: 20, enumType: StatutGuilde::class, options: ['default' => 'membre'])]
    private StatutGuilde $statut = StatutGuilde::MEMBRE;

    /** Null tant que la candidature n'est pas acceptée. */
    #[ORM\Column(name: 'rejoint_le', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rejointLe = null;

    #[ORM\Column(name: 'candidate_le', type: 'datetime_immutable')]
    private \DateTimeImmutable $candidateLe;

    public function __construct()
    {
        $this->candidateLe = new \DateTimeImmutable();
    }

    public function estMembre(): bool
    {
        return $this->statut === StatutGuilde::MEMBRE;
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

    public function getGuilde(): ?Guilde
    {
        return $this->guilde;
    }

    public function setGuilde(?Guilde $guilde): self
    {
        $this->guilde = $guilde;

        return $this;
    }

    public function getGrade(): GradeGuilde
    {
        return $this->grade;
    }

    public function setGrade(GradeGuilde $grade): self
    {
        $this->grade = $grade;

        return $this;
    }

    public function getStatut(): StatutGuilde
    {
        return $this->statut;
    }

    public function setStatut(StatutGuilde $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getRejointLe(): ?\DateTimeImmutable
    {
        return $this->rejointLe;
    }

    public function setRejointLe(?\DateTimeImmutable $rejointLe): self
    {
        $this->rejointLe = $rejointLe;

        return $this;
    }

    public function getCandidateLe(): \DateTimeImmutable
    {
        return $this->candidateLe;
    }

    public function setCandidateLe(\DateTimeImmutable $candidateLe): self
    {
        $this->candidateLe = $candidateLe;

        return $this;
    }
}
