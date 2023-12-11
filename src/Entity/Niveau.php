<?php

namespace App\Entity;

use App\Repository\NiveauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity(repositoryClass=NiveauRepository::class)
 */
class Niveau
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $niveau;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $experience;

    /**
     * @ORM\OneToMany(targetEntity=NiveauJoueur::class, mappedBy="niveau")
     *  @Groups({"users_read"})
     */
    private $niveauJoueurs;

    public function __construct()
    {
        $this->niveauJoueurs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getExperience(): ?int
    {
        return $this->experience;
    }

    public function setExperience(int $experience): self
    {
        $this->experience = $experience;

        return $this;
    }

    /**
     * @return Collection|NiveauJoueur[]
     */
    public function getNiveauJoueurs(): Collection
    {
        return $this->niveauJoueurs;
    }

    public function addNiveauJoueur(NiveauJoueur $niveauJoueur): self
    {
        if (!$this->niveauJoueurs->contains($niveauJoueur)) {
            $this->niveauJoueurs[] = $niveauJoueur;
            $niveauJoueur->setNiveau($this);
        }

        return $this;
    }

    public function removeNiveauJoueur(NiveauJoueur $niveauJoueur): self
    {
        if ($this->niveauJoueurs->removeElement($niveauJoueur)) {
            // set the owning side to null (unless already changed)
            if ($niveauJoueur->getNiveau() === $this) {
                $niveauJoueur->setNiveau(null);
            }
        }

        return $this;
    }
}
