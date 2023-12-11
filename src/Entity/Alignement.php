<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\AlignementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=AlignementRepository::class)
 */
class Alignement
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $nom;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $description;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $couleur;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $caracteristiquePrincipale;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $caracteristiqueSecondaire;

    /**
     * @ORM\OneToOne(targetEntity=Carte::class, cascade={"persist", "remove"})
     */
    private $carte;

    /**
     * @ORM\OneToMany(targetEntity=User::class, mappedBy="alignement")
     */
    private $users;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $icone;

    /**
     * @ORM\OneToMany(targetEntity=Guilde::class, mappedBy="alignement")
     */
    private $guildes;

    /**
     * @ORM\OneToMany(targetEntity=Quete::class, mappedBy="alignement")
     */
    private $quetes;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->guildes = new ArrayCollection();
        $this->quetes = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(string $couleur): self
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getCaracteristiquePrincipale(): ?int
    {
        return $this->caracteristiquePrincipale;
    }

    public function setCaracteristiquePrincipale(?int $caracteristiquePrincipale): self
    {
        $this->caracteristiquePrincipale = $caracteristiquePrincipale;

        return $this;
    }

    public function getCaracteristiqueSecondaire(): ?int
    {
        return $this->caracteristiqueSecondaire;
    }

    public function setCaracteristiqueSecondaire(?int $caracteristique_secondaire): self
    {
        $this->caracteristiqueSecondaire = $caracteristique_secondaire;

        return $this;
    }

    public function getCarte(): ?Carte
    {
        return $this->carte;
    }

    public function setCarte(?Carte $carte): self
    {
        $this->carte = $carte;

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
            $user->setAlignement($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getAlignement() === $this) {
                $user->setAlignement(null);
            }
        }

        return $this;
    }

    public function getIcone(): ?string
    {
        return $this->icone;
    }

    public function setIcone(string $icone): self
    {
        $this->icone = $icone;

        return $this;
    }

    /**
     * @return Collection|Guilde[]
     */
    public function getGuildes(): Collection
    {
        return $this->guildes;
    }

    public function addGuilde(Guilde $guilde): self
    {
        if (!$this->guildes->contains($guilde)) {
            $this->guildes[] = $guilde;
            $guilde->setAlignement($this);
        }

        return $this;
    }

    public function removeGuilde(Guilde $guilde): self
    {
        if ($this->guildes->removeElement($guilde)) {
            // set the owning side to null (unless already changed)
            if ($guilde->getAlignement() === $this) {
                $guilde->setAlignement(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Quete>
     */
    public function getQuetes(): Collection
    {
        return $this->quetes;
    }

    public function addQuete(Quete $quete): self
    {
        if (!$this->quetes->contains($quete)) {
            $this->quetes[] = $quete;
            $quete->setAlignement($this);
        }

        return $this;
    }

    public function removeQuete(Quete $quete): self
    {
        if ($this->quetes->removeElement($quete)) {
            // set the owning side to null (unless already changed)
            if ($quete->getAlignement() === $this) {
                $quete->setAlignement(null);
            }
        }

        return $this;
    }
}
