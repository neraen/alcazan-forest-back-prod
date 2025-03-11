<?php

namespace App\DTO\Equipement;

use App\DTO\Equipement\Object\Equipement;

class CreateEquipementDTO
{
    private Equipement $equipement;

    public function getEquipement(): Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(Equipement $equipement): self
    {
        $this->equipement = $equipement;

        return $this;
    }
}