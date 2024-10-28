<?php

namespace App\Entity;

use App\Repository\QueteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QueteRepository::class)]
class Quete
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\OneToMany(mappedBy: 'quete', targetEntity: UserQuete::class)]
    private $userQuetes;

    #[ORM\OneToMany(mappedBy: 'quete', targetEntity: Sequence::class)]
    private $sequences;

    #[ORM\OneToMany(mappedBy: 'quete', targetEntity: Pnj::class)]
    private $pnjs;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $minimalLevel;

    #[ORM\ManyToOne(targetEntity: Alignement::class, inversedBy: 'quetes')]
    private $alignement;

    #[ORM\ManyToOne(targetEntity: Quete::class)]
    private $quete;

    #[ORM\ManyToOne(targetEntity: Objet::class)]
    private $objet;


    public function __construct()
    {
        $this->userQuetes = new ArrayCollection();
        $this->sequences = new ArrayCollection();
        $this->pnjs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|UserQuete[]
     */
    public function getUserQuetes(): Collection
    {
        return $this->userQuetes;
    }

    public function addUserQuete(UserQuete $userQuete): self
    {
        if (!$this->userQuetes->contains($userQuete)) {
            $this->userQuetes[] = $userQuete;
            $userQuete->setQuete($this);
        }

        return $this;
    }

    public function removeUserQuete(UserQuete $userQuete): self
    {
        if ($this->userQuetes->removeElement($userQuete)) {
            // set the owning side to null (unless already changed)
            if ($userQuete->getQuete() === $this) {
                $userQuete->setQuete(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Sequence[]
     */
    public function getSequences(): Collection
    {
        return $this->sequences;
    }

    public function addSequence(Sequence $sequence): self
    {
        if (!$this->sequences->contains($sequence)) {
            $this->sequences[] = $sequence;
            $sequence->setQuete($this);
        }

        return $this;
    }

    public function removeSequence(Sequence $sequence): self
    {
        if ($this->sequences->removeElement($sequence)) {
            // set the owning side to null (unless already changed)
            if ($sequence->getQuete() === $this) {
                $sequence->setQuete(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Pnj[]
     */
    public function getPnjs(): Collection
    {
        return $this->pnjs;
    }

    public function addPnj(Pnj $pnj): self
    {
        if (!$this->pnjs->contains($pnj)) {
            $this->pnjs[] = $pnj;
            $pnj->setQuete($this);
        }

        return $this;
    }

    public function removePnj(Pnj $pnj): self
    {
        if ($this->pnjs->removeElement($pnj)) {
            // set the owning side to null (unless already changed)
            if ($pnj->getQuete() === $this) {
                $pnj->setQuete(null);
            }
        }

        return $this;
    }

    public function getMinimalLevel(): ?int
    {
        return $this->minimalLevel;
    }

    public function setMinimalLevel(?int $minimalLevel): self
    {
        $this->minimalLevel = $minimalLevel;

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

    public function getQuete(): ?self
    {
        return $this->quete;
    }

    public function setQuete(?self $quete): self
    {
        $this->quete = $quete;

        return $this;
    }

    public function getObjet(): ?Objet
    {
        return $this->objet;
    }

    public function setObjet(?Objet $objet): self
    {
        $this->objet = $objet;

        return $this;
    }
}
