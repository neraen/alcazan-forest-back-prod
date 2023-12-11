<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Controller\JoueurNiveauController;
use App\Repository\NiveauJoueurRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity(repositoryClass=NiveauJoueurRepository::class)
 */
//#[
//    ApiResource(
//        collectionOperations: [
//            'get',
//            'post',
//            'getAllCaracteristiques' => [
//                'method' => 'GET',
//                'path' => '/joueur/caracteristiques',
//                'controller' => JoueurCaracteristiqueController::class
//            ]
//        ]
//    )
//]
#[
    ApiResource(
        normalizationContext: ["groups"=> ["level_read"]],
        collectionOperations: [
            'getLevelAndExperience' => [
                'method' => 'GET',
                'path' => '/joueur/niveau',
                'controller' => JoueurNiveauController::class
            ]
        ]
    )
]
class NiveauJoueur
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity=User::class, cascade={"persist", "remove"}, inversedBy="niveauJoueur")
     * @Groups({"level_read"})
     */
    private $user;

    /**
     * @ORM\ManyToOne(targetEntity=Niveau::class, inversedBy="niveauJoueurs")
     * @Groups({"users_read", "level_read"})
     */
    private $niveau;

    /**
     * @ORM\Column(type="integer")
     * @Groups({"users_read", "level_read"})
     */
    private $experience;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getNiveau(): ?Niveau
    {
        return $this->niveau;
    }

    public function setNiveau(?Niveau $niveau): self
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
}
