<?php

namespace App\Entity;

use App\Repository\BossRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BossRepository::class)
 */
class Boss
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
    private $name;

    /**
     * @ORM\Column(type="integer")
     */
    private $maxLife;

    /**
     * @ORM\Column(type="integer")
     */
    private $actualLife;

    /**
     * @ORM\Column(type="integer")
     */
    private $puissance;

    /**
     * @ORM\OneToMany(targetEntity=BossEquipement::class, mappedBy="boss")
     */
    private $bossEquipements;

    /**
     * @ORM\OneToMany(targetEntity=BossObjet::class, mappedBy="boss")
     */
    private $bossObjets;

    /**
     * @ORM\OneToMany(targetEntity=BossSortilege::class, mappedBy="boss")
     */
    private $bossSortileges;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $icone;

    /**
     * @ORM\OneToMany(targetEntity=CarteCarreau::class, mappedBy="boss")
     */
    private $carteCarreaus;

    /**
     * @ORM\OneToMany(targetEntity=UserBoss::class, mappedBy="boss")
     */
    private $userBosses;

    /**
     * @ORM\OneToMany(targetEntity=BossRecompense::class, mappedBy="boss")
     */
    private $bossRecompenses;

    /**
     * @ORM\OneToMany(targetEntity=Action::class, mappedBy="boss")
     */
    private $actions;

    public function __construct()
    {
        $this->bossEquipements = new ArrayCollection();
        $this->bossObjets = new ArrayCollection();
        $this->bossSortileges = new ArrayCollection();
        $this->carteCarreaus = new ArrayCollection();
        $this->userBosses = new ArrayCollection();
        $this->bossRecompenses = new ArrayCollection();
        $this->actions = new ArrayCollection();
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

    public function getMaxLife(): ?int
    {
        return $this->maxLife;
    }

    public function setMaxLife(int $maxLife): self
    {
        $this->maxLife = $maxLife;

        return $this;
    }

    public function getActualLife(): ?int
    {
        return $this->actualLife;
    }

    public function setActualLife(int $actualLife): self
    {
        $this->actualLife = $actualLife;

        return $this;
    }

    public function getPuissance(): ?int
    {
        return $this->puissance;
    }

    public function setPuissance(int $puissance): self
    {
        $this->puissance = $puissance;

        return $this;
    }

    /**
     * @return Collection|BossEquipement[]
     */
    public function getBossEquipements(): Collection
    {
        return $this->bossEquipements;
    }

    public function addBossEquipement(BossEquipement $bossEquipement): self
    {
        if (!$this->bossEquipements->contains($bossEquipement)) {
            $this->bossEquipements[] = $bossEquipement;
            $bossEquipement->setBoss($this);
        }

        return $this;
    }

    public function removeBossEquipement(BossEquipement $bossEquipement): self
    {
        if ($this->bossEquipements->removeElement($bossEquipement)) {
            // set the owning side to null (unless already changed)
            if ($bossEquipement->getBoss() === $this) {
                $bossEquipement->setBoss(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BossObjet[]
     */
    public function getBossObjets(): Collection
    {
        return $this->bossObjets;
    }

    public function addBossObjet(BossObjet $bossObjet): self
    {
        if (!$this->bossObjets->contains($bossObjet)) {
            $this->bossObjets[] = $bossObjet;
            $bossObjet->setBoss($this);
        }

        return $this;
    }

    public function removeBossObjet(BossObjet $bossObjet): self
    {
        if ($this->bossObjets->removeElement($bossObjet)) {
            // set the owning side to null (unless already changed)
            if ($bossObjet->getBoss() === $this) {
                $bossObjet->setBoss(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BossSortilege[]
     */
    public function getBossSortileges(): Collection
    {
        return $this->bossSortileges;
    }

    public function addBossSortilege(BossSortilege $bossSortilege): self
    {
        if (!$this->bossSortileges->contains($bossSortilege)) {
            $this->bossSortileges[] = $bossSortilege;
            $bossSortilege->setBoss($this);
        }

        return $this;
    }

    public function removeBossSortilege(BossSortilege $bossSortilege): self
    {
        if ($this->bossSortileges->removeElement($bossSortilege)) {
            // set the owning side to null (unless already changed)
            if ($bossSortilege->getBoss() === $this) {
                $bossSortilege->setBoss(null);
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
     * @return Collection|CarteCarreau[]
     */
    public function getCarteCarreaus(): Collection
    {
        return $this->carteCarreaus;
    }

    public function addCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if (!$this->carteCarreaus->contains($carteCarreau)) {
            $this->carteCarreaus[] = $carteCarreau;
            $carteCarreau->setBoss($this);
        }

        return $this;
    }

    public function removeCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if ($this->carteCarreaus->removeElement($carteCarreau)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreau->getBoss() === $this) {
                $carteCarreau->setBoss(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|UserBoss[]
     */
    public function getUserBosses(): Collection
    {
        return $this->userBosses;
    }

    public function addUserBoss(UserBoss $userBoss): self
    {
        if (!$this->userBosses->contains($userBoss)) {
            $this->userBosses[] = $userBoss;
            $userBoss->setBoss($this);
        }

        return $this;
    }

    public function removeUserBoss(UserBoss $userBoss): self
    {
        if ($this->userBosses->removeElement($userBoss)) {
            // set the owning side to null (unless already changed)
            if ($userBoss->getBoss() === $this) {
                $userBoss->setBoss(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|BossRecompense[]
     */
    public function getBossRecompenses(): Collection
    {
        return $this->bossRecompenses;
    }

    public function addBossRecompense(BossRecompense $bossRecompense): self
    {
        if (!$this->bossRecompenses->contains($bossRecompense)) {
            $this->bossRecompenses[] = $bossRecompense;
            $bossRecompense->setBoss($this);
        }

        return $this;
    }

    public function removeBossRecompense(BossRecompense $bossRecompense): self
    {
        if ($this->bossRecompenses->removeElement($bossRecompense)) {
            // set the owning side to null (unless already changed)
            if ($bossRecompense->getBoss() === $this) {
                $bossRecompense->setBoss(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Action>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(Action $action): self
    {
        if (!$this->actions->contains($action)) {
            $this->actions[] = $action;
            $action->setBoss($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getBoss() === $this) {
                $action->setBoss(null);
            }
        }

        return $this;
    }
}
