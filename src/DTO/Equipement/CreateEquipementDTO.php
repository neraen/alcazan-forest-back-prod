<?php

namespace App\DTO\Equipement;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload de POST /api/equipement/create : {"equipement": {...}}.
 *
 * Le contenu reste un tableau associatif : le formulaire d'admin envoie un mélange de champs
 * scalaires et de caractéristiques, et le contrôleur le consomme tel quel.
 */
class CreateEquipementDTO
{
    #[Assert\NotBlank]
    private array $equipement = [];

    public function getEquipement(): array
    {
        return $this->equipement;
    }

    public function setEquipement(array $equipement): self
    {
        $this->equipement = $equipement;

        return $this;
    }
}
