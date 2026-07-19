<?php

namespace App\DTO\Spell;

use Symfony\Component\Validator\Constraints as Assert;

class SpellSlotDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ spellId est obligatoire.")]
        public readonly ?int $spellId = null,
        #[Assert\NotBlank(message: "Le champ position est obligatoire.")]
        #[Assert\Range(min: 1, max: 8, notInRangeMessage: "L'emplacement doit être compris entre 1 et 8.")]
        public readonly ?int $position = null,
    ) {}
}
