<?php

namespace App\Entity;

use App\Repository\BuffRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BuffRepository::class)]
class Buff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $icone;

    #[ORM\Column(type: 'boolean')]
    private $isCarac;

    #[ORM\Column(type: 'boolean')]
    private $isDispell;

    #[ORM\Column(type: 'boolean')]
    private $isBlocage;


    #[ORM\Column(type: 'integer', nullable: true)]
    private $value;

    #[ORM\OneToMany(mappedBy: 'buff', targetEntity: UserBuff::class)]
    private $userBuffs;

    #[ORM\OneToMany(mappedBy: 'buff', targetEntity: BuffCaracteristique::class)]
    private $buffCaracteristiques;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $duree;

    #[ORM\OneToMany(mappedBy: 'buff', targetEntity: Sortilege::class)]
    private $sortileges;

    public function __construct()
    {
        $this->userBuffs = new ArrayCollection();
        $this->buffCaracteristiques = new ArrayCollection();
        $this->sortileges = new ArrayCollection();
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

    public function getIcone(): ?string
    {
        return $this->icone;
    }

    public function setIcone(?string $icone): self
    {
        $this->icone = $icone;

        return $this;
    }

    public function getIsCarac(): ?bool
    {
        return $this->isCarac;
    }

    public function setIsCarac(bool $isCarac): self
    {
        $this->isCarac = $isCarac;

        return $this;
    }

    public function getIsDispell(): ?bool
    {
        return $this->isDispell;
    }

    public function setIsDispell(bool $isDispell): self
    {
        $this->isDispell = $isDispell;

        return $this;
    }

    public function getIsBlocage(): ?bool
    {
        return $this->isBlocage;
    }

    public function setIsBlocage(bool $isBlocage): self
    {
        $this->isBlocage = $isBlocage;

        return $this;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    public function setValue(?int $value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return Collection|UserBuff[]
     */
    public function getUserBuffs(): Collection
    {
        return $this->userBuffs;
    }

    public function addUserBuff(UserBuff $userBuff): self
    {
        if (!$this->userBuffs->contains($userBuff)) {
            $this->userBuffs[] = $userBuff;
            $userBuff->setBuff($this);
        }

        return $this;
    }

    public function removeUserBuff(UserBuff $userBuff): self
    {
        if ($this->userBuffs->removeElement($userBuff)) {
            // set the owning side to null (unless already changed)
            if ($userBuff->getBuff() === $this) {
                $userBuff->setBuff(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BuffCaracteristique[]
     */
    public function getBuffCaracteristiques(): Collection
    {
        return $this->buffCaracteristiques;
    }

    public function addBuffCaracteristique(BuffCaracteristique $buffCaracteristique): self
    {
        if (!$this->buffCaracteristiques->contains($buffCaracteristique)) {
            $this->buffCaracteristiques[] = $buffCaracteristique;
            $buffCaracteristique->setBuff($this);
        }

        return $this;
    }

    public function removeBuffCaracteristique(BuffCaracteristique $buffCaracteristique): self
    {
        if ($this->buffCaracteristiques->removeElement($buffCaracteristique)) {
            // set the owning side to null (unless already changed)
            if ($buffCaracteristique->getBuff() === $this) {
                $buffCaracteristique->setBuff(null);
            }
        }

        return $this;
    }

    public function getDuree(): ?int
    {
        return $this->duree;
    }

    public function setDuree(?int $duree): self
    {
        $this->duree = $duree;

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
            $sortilege->setBuff($this);
        }

        return $this;
    }

    public function removeSortilege(Sortilege $sortilege): self
    {
        if ($this->sortileges->removeElement($sortilege)) {
            // set the owning side to null (unless already changed)
            if ($sortilege->getBuff() === $this) {
                $sortilege->setBuff(null);
            }
        }

        return $this;
    }
}
