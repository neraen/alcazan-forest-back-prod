<?php

namespace App\Entity;

use App\Repository\MonstreObjetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonstreObjetRepository::class)]
class MonstreObjet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Monstre::class, inversedBy: 'monstreObjets')]
    private $monstre;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Objet::class, inversedBy: 'objets')]
    private $objet;

    #[ORM\Column(type: 'integer')]
    private $taux_drop;

    #[ORM\Column(type: 'string', length: 255)]
    private $typeDrop;

    #[ORM\Column(type: 'integer')]
    private $diviseurTauxDrop;

    /**
     * Métier requis pour PRÉLEVER cette ligne de butin — le dépeceur et ses peaux.
     *
     * Nul pour tout le reste : le butin ordinaire tombe pour n'importe qui, et c'est le
     * comportement de toutes les lignes existantes.
     */
    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Metier $metier = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $niveauMetierMin = 0;

    /** Expérience de métier créditée quand la ligne tombe. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $experienceMetier = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMonstre(): ?Monstre
    {
        return $this->monstre;
    }

    public function setMonstre(?Monstre $monstre): self
    {
        $this->monstre = $monstre;

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

    public function getTauxDrop(): ?int
    {
        return $this->taux_drop;
    }

    public function setTauxDrop(int $taux_drop): self
    {
        $this->taux_drop = $taux_drop;

        return $this;
    }

    public function getTypeDrop(): ?string
    {
        return $this->typeDrop;
    }

    public function setTypeDrop(string $typeDrop): self
    {
        $this->typeDrop = $typeDrop;

        return $this;
    }

    public function getDiviseurTauxDrop(): ?int
    {
        return $this->diviseurTauxDrop;
    }

    public function setDiviseurTauxDrop(int $diviseurTauxDrop): self
    {
        $this->diviseurTauxDrop = $diviseurTauxDrop;

        return $this;
    }

    public function getMetier(): ?Metier
    {
        return $this->metier;
    }

    public function setMetier(?Metier $metier): self
    {
        $this->metier = $metier;

        return $this;
    }

    public function getNiveauMetierMin(): int
    {
        return $this->niveauMetierMin;
    }

    public function setNiveauMetierMin(int $niveauMetierMin): self
    {
        $this->niveauMetierMin = max(0, $niveauMetierMin);

        return $this;
    }

    public function getExperienceMetier(): int
    {
        return $this->experienceMetier;
    }

    public function setExperienceMetier(int $experienceMetier): self
    {
        $this->experienceMetier = max(0, $experienceMetier);

        return $this;
    }

    /** Cette ligne de butin exige-t-elle un métier pour être prélevée ? */
    public function exigeUnMetier(): bool
    {
        return $this->metier !== null;
    }
}
