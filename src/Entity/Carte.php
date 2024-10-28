<?php

namespace App\Entity;

use App\Repository\CarteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CarteRepository::class)]
class Carte
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;


    #[ORM\Column(type: 'string', length: 255)]
    private $nom;


    #[ORM\Column(type: 'json')]
    private $position;

    #[ORM\OneToMany(mappedBy: 'carte', targetEntity: CarteCarreau::class)]
    private $carteCarreaux;

    #[ORM\OneToMany(mappedBy: 'map', targetEntity: User::class)]
    private $users;

    #[ORM\Column(type: 'integer')]
    private $abscisse;

    #[ORM\Column(type: 'integer')]
    private $ordonnee;

    #[ORM\Column(type: 'boolean')]
    private $isInstance;

    #[ORM\Column(type: 'boolean')]
    private $is_cimetiere;

    #[ORM\Column(type: 'boolean')]
    private $is_auberge;

    #[ORM\OneToMany(mappedBy: 'carte', targetEntity: Action::class)]
    private $actions;


    public function __construct()
    {
        $this->carteCarreaux = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->actions = new ArrayCollection();
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

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(string $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return Collection|CarteCarreau[]
     */
    public function getCarteCarreaux(): Collection
    {
        return $this->carteCarreaux;
    }

    public function addCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if (!$this->carteCarreaux->contains($carteCarreau)) {
            $this->carteCarreaux[] = $carteCarreau;
            $carteCarreau->setCarte($this);
        }

        return $this;
    }

    public function removeCarteCarreau(CarteCarreau $carteCarreau): self
    {
        if ($this->carteCarreaux->removeElement($carteCarreau)) {
            // set the owning side to null (unless already changed)
            if ($carteCarreau->getCarte() === $this) {
                $carteCarreau->setCarte(null);
            }
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
            $user->setMap($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getMap() === $this) {
                $user->setMap(null);
            }
        }

        return $this;
    }

    public function getAbscisse(): ?int
    {
        return $this->abscisse;
    }

    public function setAbscisse(int $abscisse): self
    {
        $this->abscisse = $abscisse;

        return $this;
    }

    public function getOrdonnee(): ?int
    {
        return $this->ordonnee;
    }

    public function setOrdonnee(int $ordonnee): self
    {
        $this->ordonnee = $ordonnee;

        return $this;
    }

    public function getIsInstance(): ?bool
    {
        return $this->isInstance;
    }

    public function setIsInstance(bool $isInstance): self
    {
        $this->isInstance = $isInstance;

        return $this;
    }

    public function getIsCimetiere(): ?bool
    {
        return $this->is_cimetiere;
    }

    public function setIsCimetiere(bool $is_cimetiere): self
    {
        $this->is_cimetiere = $is_cimetiere;

        return $this;
    }

    public function getIsAuberge(): ?bool
    {
        return $this->is_auberge;
    }

    public function setIsAuberge(bool $is_auberge): self
    {
        $this->is_auberge = $is_auberge;

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
            $action->setCarte($this);
        }

        return $this;
    }

    public function removeAction(Action $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getCarte() === $this) {
                $action->setCarte(null);
            }
        }

        return $this;
    }
}
