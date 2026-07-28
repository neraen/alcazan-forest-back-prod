<?php

namespace App\DTO\Echange;

use Symfony\Component\Validator\Constraints as Assert;

class EchangeVersionDTO
{
    public function __construct(
        #[Assert\NotNull(message: "Le champ expectedVersion est obligatoire.")]
        #[Assert\PositiveOrZero(message: "Le champ expectedVersion est invalide.")]
        public readonly ?int $expectedVersion = null,
    ) {}
}
