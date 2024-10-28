<?php

namespace App\Entity;

use App\Repository\GradeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GradeRepository::class)]
class Grade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $nom;

    #[ORM\Column(type: 'string', length: 255)]
    private $icone;

    #[ORM\OneToMany(mappedBy: 'grade', targetEntity: JoueurGrade::class)]
    private $joueurGrades;

    public function __construct()
    {
        $this->joueurGrades = new ArrayCollection();
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
     * @return Collection|JoueurGrade[]
     */
    public function getJoueurGrades(): Collection
    {
        return $this->joueurGrades;
    }

    public function addJoueurGrade(JoueurGrade $joueurGrade): self
    {
        if (!$this->joueurGrades->contains($joueurGrade)) {
            $this->joueurGrades[] = $joueurGrade;
            $joueurGrade->setGrade($this);
        }

        return $this;
    }

    public function removeJoueurGrade(JoueurGrade $joueurGrade): self
    {
        if ($this->joueurGrades->removeElement($joueurGrade)) {
            // set the owning side to null (unless already changed)
            if ($joueurGrade->getGrade() === $this) {
                $joueurGrade->setGrade(null);
            }
        }

        return $this;
    }
}
