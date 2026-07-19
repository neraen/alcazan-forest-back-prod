<?php

namespace App\DTO\Spell;

use Symfony\Component\Validator\Constraints as Assert;

class SpellIdDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ spellId est obligatoire.")]
        public readonly ?int $spellId = null,
    ) {}
}
