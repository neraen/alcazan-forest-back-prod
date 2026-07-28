<?php

namespace App\Entity;

use App\Repository\UserQueteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progression d'un joueur sur une quête : pointeur vers la séquence
 * courante + drapeau de complétion. Une seule ligne par (user, quete).
 */
#[ORM\Entity(repositoryClass: UserQueteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_quete', columns: ['user_id', 'quete_id'])]
class UserQuete
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userQuetes')]
    private $user;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Quete::class, inversedBy: 'userQuetes')]
    private $quete;

    #[ORM\Column(type: 'boolean')]
    private $isDone;

    #[ORM\ManyToOne(targetEntity: Sequence::class, inversedBy: 'userQuetes')]
    private $sequence;

    /**
     * Où en étaient les compteurs du joueur quand il est ENTRÉ dans l'étape courante :
     * {"monstre_tue:12": 47, "objet_fabrique:3": 2}.
     *
     * Sans cet instantané, « tuez 5 loups » se lirait sur un compteur cumulatif et se
     * validerait instantanément pour tout joueur qui en a déjà tué cinq dans sa vie —
     * la quête ne demanderait plus rien. Il est reposé à CHAQUE changement de séquence,
     * et jamais quand le joueur reclique sur une étape qu'il n'a pas encore franchie :
     * sinon sa progression repartirait de zéro à chaque tentative.
     *
     * Clé absente (étape entamée avant la mise en place, action rebranchée depuis) =
     * départ 0, donc lecture cumulative : une dégradation lisible, jamais un blocage.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $compteursDepart = null;

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

    public function getQuete(): ?Quete
    {
        return $this->quete;
    }

    public function setQuete(?Quete $quete): self
    {
        $this->quete = $quete;

        return $this;
    }

    public function getIsDone(): ?bool
    {
        return $this->isDone;
    }

    public function setIsDone(bool $isDone): self
    {
        $this->isDone = $isDone;

        return $this;
    }

    public function getSequence(): ?Sequence
    {
        return $this->sequence;
    }

    public function setSequence(?Sequence $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    /** @return array<string, int> */
    public function getCompteursDepart(): array
    {
        return $this->compteursDepart ?? [];
    }

    /** @param array<string, int> $compteursDepart */
    public function setCompteursDepart(?array $compteursDepart): self
    {
        $this->compteursDepart = $compteursDepart === [] ? null : $compteursDepart;

        return $this;
    }

    /** Valeur du compteur `$cle` au moment où le joueur a reçu l'étape (0 par défaut). */
    public function getCompteurDepart(string $cle): int
    {
        return (int)($this->compteursDepart[$cle] ?? 0);
    }
}
