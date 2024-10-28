<?php

namespace App\Entity;

use App\Repository\GuildeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;


#[ORM\Entity(repositoryClass: GuildeRepository::class)]
class Guilde
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $nom;

    #[ORM\Column(type: 'text', nullable: true)]
    private $description;

    #[ORM\Column(type: 'integer')]
    private $placeMax;

    #[ORM\OneToMany(mappedBy: 'guilde', targetEntity: User::class)]
    private $users;

    #[ORM\OneToMany(mappedBy: 'guilde', targetEntity: JoueurGuilde::class)]
    private $joueurGuildes;

    #[ORM\ManyToOne(targetEntity: Alignement::class, inversedBy: 'guildes')]
    private $alignement;

    #[ORM\Column(type: 'integer')]
    private $niveau;

    #[ORM\Column(type: 'string', length: 255)]
    private $icone;

    #[ORM\Column(type: 'string', length: 255)]
    private $banner;

    #[ORM\Column(type: 'integer')]
    private $nbJoueurMax;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->joueurGuildes = new ArrayCollection();
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

    public function getPlaceMax(): ?int
    {
        return $this->placeMax;
    }

    public function setPlaceMax(int $place_max): self
    {
        $this->placeMax = $place_max;

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
            $user->setGuilde($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getGuilde() === $this) {
                $user->setGuilde(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|JoueurGuilde[]
     */
    public function getJoueurGuildes(): Collection
    {
        return $this->joueurGuildes;
    }

    public function addJoueurGuilde(JoueurGuilde $joueurGuilde): self
    {
        if (!$this->joueurGuildes->contains($joueurGuilde)) {
            $this->joueurGuildes[] = $joueurGuilde;
            $joueurGuilde->setGuilde($this);
        }

        return $this;
    }

    public function removeJoueurGuilde(JoueurGuilde $joueurGuilde): self
    {
        if ($this->joueurGuildes->removeElement($joueurGuilde)) {
            // set the owning side to null (unless already changed)
            if ($joueurGuilde->getGuilde() === $this) {
                $joueurGuilde->setGuilde(null);
            }
        }

        return $this;
    }

    public function getAlignement(): ?Alignement
    {
        return $this->alignement;
    }

    public function setAlignement(?Alignement $alignement): self
    {
        $this->alignement = $alignement;

        return $this;
    }

    public function getNiveau(): ?int
    {
        return $this->niveau;
    }

    public function setNiveau(int $niveau): self
    {
        $this->niveau = $niveau;

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

    public function getBanner(): ?string
    {
        return $this->banner;
    }

    public function setBanner(string $banner): self
    {
        $this->banner = $banner;

        return $this;
    }

    public function getNbJoueurMax(): ?int
    {
        return $this->nbJoueurMax;
    }

    public function setNbJoueurMax(int $nbJoueurMax): self
    {
        $this->nbJoueurMax = $nbJoueurMax;

        return $this;
    }
}
