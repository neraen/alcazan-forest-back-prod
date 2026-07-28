<?php

namespace App\DTO\Echange;

use Symfony\Component\Validator\Constraints as Assert;

class EchangeIdDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ echangeId est obligatoire.")]
        #[Assert\Positive(message: "Le champ echangeId est invalide.")]
        public readonly ?int $echangeId = null,
    ) {}
}
