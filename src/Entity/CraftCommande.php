<?php

namespace App\Entity;

use App\Enum\ModeCraft;
use App\Enum\StatutCraft;
use App\Repository\CraftCommandeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une fabrication lancée par un joueur = RUNTIME (jamais exporté dans le seed —
 * `craft_commande` est dans la liste noire de `scripts/content-dump.sh`).
 *
 * **Résolution paresseuse.** Rien ne « termine » une commande : `pretAt` est posé au
 * lancement, et l'état se déduit de l'horloge serveur quand le joueur revient. Pas de
 * tâche périodique — le scheduler tourne à la minute et travaillerait pour des joueurs
 * déconnectés, exactement ce qu'on évite déjà pour le tick de donjon.
 *
 * **`ingredients` est un INSTANTANÉ** des lignes réellement débitées, figé au lancement.
 * Le recyclage rend depuis cet instantané, jamais depuis la recette : celle-ci peut être
 * éditée pendant que la commande cuit, et rendre alors autre chose que ce qui a été pris
 * serait une porte ouverte à la duplication d'items.
 */
#[ORM\Entity(repositoryClass: CraftCommandeRepository::class)]
#[ORM\Index(name: 'idx_craft_commande_user', columns: ['user_id', 'statut'])]
class CraftCommande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Recette::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Recette $recette = null;

    #[ORM\Column(type: 'string', length: 16, enumType: ModeCraft::class)]
    private ModeCraft $mode = ModeCraft::RECYCLAGE;

    #[ORM\Column(type: 'string', length: 16, enumType: StatutCraft::class)]
    private StatutCraft $statut = StatutCraft::EN_COURS;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lanceeAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $pretAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retireeAt = null;

    /** @var array<int, array{type: string, itemId: int, quantite: int, nom: string}> */
    #[ORM\Column(type: 'json')]
    private array $ingredients = [];

    public function __construct()
    {
        $this->lanceeAt = new \DateTimeImmutable();
        $this->pretAt = $this->lanceeAt;
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

    public function getRecette(): ?Recette
    {
        return $this->recette;
    }

    public function setRecette(?Recette $recette): self
    {
        $this->recette = $recette;

        return $this;
    }

    public function getMode(): ModeCraft
    {
        return $this->mode;
    }

    public function setMode(ModeCraft $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getStatut(): StatutCraft
    {
        return $this->statut;
    }

    public function setStatut(StatutCraft $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getLanceeAt(): \DateTimeImmutable
    {
        return $this->lanceeAt;
    }

    public function setLanceeAt(\DateTimeImmutable $lanceeAt): self
    {
        $this->lanceeAt = $lanceeAt;

        return $this;
    }

    public function getPretAt(): \DateTimeImmutable
    {
        return $this->pretAt;
    }

    public function setPretAt(\DateTimeImmutable $pretAt): self
    {
        $this->pretAt = $pretAt;

        return $this;
    }

    public function getRetireeAt(): ?\DateTimeImmutable
    {
        return $this->retireeAt;
    }

    public function setRetireeAt(?\DateTimeImmutable $retireeAt): self
    {
        $this->retireeAt = $retireeAt;

        return $this;
    }

    public function getIngredients(): array
    {
        return $this->ingredients;
    }

    public function setIngredients(array $ingredients): self
    {
        $this->ingredients = $ingredients;

        return $this;
    }

    /** « Prête » se DÉDUIT, ne se stocke pas : voir StatutCraft. */
    public function estPrete(?\DateTimeImmutable $maintenant = null): bool
    {
        return $this->statut === StatutCraft::EN_COURS
            && ($maintenant ?? new \DateTimeImmutable()) >= $this->pretAt;
    }
}
