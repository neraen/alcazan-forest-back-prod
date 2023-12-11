<?php

namespace App\Entity;

use App\Repository\SortilegeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity(repositoryClass=SortilegeRepository::class)
 */
class Sortilege
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"users_read"})
     */
    private $nom;

    /**
     * @ORM\ManyToOne(targetEntity=Classe::class, inversedBy="sortileges")
     * @ORM\JoinColumn(nullable=false)
     */
    private $classe;

    /**
     * @ORM\Column(type="float")
     * @Groups({"users_read"})
     */
    private $cooldown;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $degatBase;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"users_read"})
     */
    private $icone;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read"})
     */
    private $niveau;

    /**
     * @ORM\Column(type="integer")
     */
    private $portee;

    /**
     * @ORM\Column(type="integer")
     */
    private $caracteristiqueDegat;

    /**
     * @ORM\Column(type="integer")
     */
    private $caracteristiqueEquilibre;

    /**
     * @ORM\Column(type="float")
     */
    private $coefPrincipal;

    /**
     * @ORM\Column(type="float")
     */
    private $coefSecondaire;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $type;

    /**
     * @ORM\Column(type="integer")
     */
    private $pointAction;

    /**
     * @ORM\ManyToOne(targetEntity=Buff::class, inversedBy="sortileges")
     */
    private $buff;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $description;

    /**
     * @ORM\OneToMany(targetEntity=UserSortilege::class, mappedBy="sortilege")
     */
    private $userSortileges;

    public function __construct()
    {
        $this->userSortileges = new ArrayCollection();
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

    public function getClasse(): ?Classe
    {
        return $this->classe;
    }

    public function setClasse(?Classe $classe): self
    {
        $this->classe = $classe;

        return $this;
    }

    public function getCooldown(): ?float
    {
        return $this->cooldown;
    }

    public function setCooldown(float $cooldown): self
    {
        $this->cooldown = $cooldown;

        return $this;
    }

    public function getDegatBase(): ?int
    {
        return $this->degatBase;
    }

    public function setDegatBase(int $degat_base): self
    {
        $this->degatBase = $degat_base;

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

    public function getNiveau(): ?int
    {
        return $this->niveau;
    }

    public function setNiveau(int $niveau): self
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getPortee(): ?int
    {
        return $this->portee;
    }

    public function setPortee(int $portee): self
    {
        $this->portee = $portee;

        return $this;
    }

    public function getCaracteristiqueDegat(): ?int
    {
        return $this->caracteristiqueDegat;
    }

    public function setCaracteristiqueDegat(int $caracteristique_degat): self
    {
        $this->caracteristiqueDegat = $caracteristique_degat;

        return $this;
    }

    public function getCaracteristiqueEquilibre(): ?int
    {
        return $this->caracteristiqueEquilibre;
    }

    public function setCaracteristiqueEquilibre(int $caracteristique_equilibre): self
    {
        $this->caracteristiqueEquilibre = $caracteristique_equilibre;

        return $this;
    }

    public function getCoefPrincipal(): ?float
    {
        return $this->coefPrincipal;
    }

    public function setCoefPrincipal(float $coefPrincipal): self
    {
        $this->coefPrincipal = $coefPrincipal;

        return $this;
    }

    public function getCoefSecondaire(): ?float
    {
        return $this->coefSecondaire;
    }

    public function setCoefSecondaire(float $coefSecondaire): self
    {
        $this->coefSecondaire = $coefSecondaire;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPointAction(): ?int
    {
        return $this->pointAction;
    }

    public function setPointAction(int $pointAction): self
    {
        $this->pointAction = $pointAction;

        return $this;
    }

    public function getBuff(): ?Buff
    {
        return $this->buff;
    }

    public function setBuff(?Buff $buff): self
    {
        $this->buff = $buff;

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

    /**
     * @return Collection<int, UserSortilege>
     */
    public function getUserSortileges(): Collection
    {
        return $this->userSortileges;
    }

    public function addUserSortilege(UserSortilege $userSortilege): self
    {
        if (!$this->userSortileges->contains($userSortilege)) {
            $this->userSortileges[] = $userSortilege;
            $userSortilege->setSortilege($this);
        }

        return $this;
    }

    public function removeUserSortilege(UserSortilege $userSortilege): self
    {
        if ($this->userSortileges->removeElement($userSortilege)) {
            // set the owning side to null (unless already changed)
            if ($userSortilege->getSortilege() === $this) {
                $userSortilege->setSortilege(null);
            }
        }

        return $this;
    }
}
