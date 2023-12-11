<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\ClasseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity(repositoryClass=ClasseRepository::class)
 */
class Classe
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"users_read", "carte_read"})
     */
    private $nom;

    /**
     * @ORM\OneToMany(targetEntity=Sortilege::class, mappedBy="classe")
     * @Groups({"users_read"})
     */
    private $sortileges;

    /**
     * @ORM\ManyToMany(targetEntity=Equipement::class, mappedBy="classe")
     */
    private $equipements;

    /**
     * @ORM\OneToMany(targetEntity=User::class, mappedBy="classe")
     */
    private $users;

    public function __construct()
    {
        $this->sortileges = new ArrayCollection();
        $this->equipements = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    /**
     * @return Collection|Sortilege[]
     */
    public function getSortileges(): Collection
    {
        return $this->sortileges;
    }

    public function addSortilege(Sortilege $sortilege): self
    {
        if (!$this->sortileges->contains($sortilege)) {
            $this->sortileges[] = $sortilege;
            $sortilege->setClasse($this);
        }

        return $this;
    }

    public function removeSortilege(Sortilege $sortilege): self
    {
        if ($this->sortileges->removeElement($sortilege)) {
            // set the owning side to null (unless already changed)
            if ($sortilege->getClasse() === $this) {
                $sortilege->setClasse(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Equipement[]
     */
    public function getEquipements(): Collection
    {
        return $this->equipements;
    }

    public function addEquipement(Equipement $equipement): self
    {
        if (!$this->equipements->contains($equipement)) {
            $this->equipements[] = $equipement;
            $equipement->addClasse($this);
        }

        return $this;
    }

    public function removeEquipement(Equipement $equipement): self
    {
        if ($this->equipements->removeElement($equipement)) {
            $equipement->removeClasse($this);
        }

        return $this;
    }

    /**
     * @return Collection|User[]
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users[] = $user;
            $user->setClasse($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getClasse() === $this) {
                $user->setClasse(null);
            }
        }

        return $this;
    }
}
