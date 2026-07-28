<?php

namespace App\Entity;

use App\Repository\UserEquipementRepository;
use Doctrine\ORM\Mapping as ORM;

// Un même objet ne peut pas être porté deux fois par le même joueur (la règle « un seul
// équipement par position » est tenue par EquipementEquipeService, non exprimable en index).
#[ORM\Entity(repositoryClass: UserEquipementRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_equipement', columns: ['user_id', 'equipement_id'])]
class UserEquipement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userEquipements')]
    private $user;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Equipement::class, inversedBy: 'userEquipements')]
    private $equipement;

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

    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;

        return $this;
    }
}
