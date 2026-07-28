<?php

namespace App\Entity;

use App\Enum\TypeItem;
use App\Repository\EchangeLigneRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un item proposé dans une session d'échange : {type, itemId, quantité} — le jeu n'a pas
 * d'instance d'objet (pas de durabilité/enchantement), une pile suffit. Une seule ligne par
 * (échange, joueur, item) : re-proposer le même item ajuste la quantité.
 */
#[ORM\Entity(repositoryClass: EchangeLigneRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_echange_ligne', columns: ['echange_id', 'proprietaire_id', 'type', 'item_id'])]
class EchangeLigne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Echange::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Echange $echange;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $proprietaire;

    #[ORM\Column(type: 'string', length: 20, enumType: TypeItem::class)]
    private TypeItem $type;

    #[ORM\Column(type: 'integer')]
    private int $itemId;

    #[ORM\Column(type: 'integer')]
    private int $quantite;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEchange(): Echange
    {
        return $this->echange;
    }

    public function setEchange(Echange $echange): self
    {
        $this->echange = $echange;

        return $this;
    }

    public function getProprietaire(): User
    {
        return $this->proprietaire;
    }

    public function setProprietaire(User $proprietaire): self
    {
        $this->proprietaire = $proprietaire;

        return $this;
    }

    public function getType(): TypeItem
    {
        return $this->type;
    }

    public function setType(TypeItem $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): self
    {
        $this->itemId = $itemId;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
