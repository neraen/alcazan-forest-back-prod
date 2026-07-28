<?php

namespace App\DTO\Echange;

use Symfony\Component\Validator\Constraints as Assert;

class EchangeItemRemoveDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ ligneId est obligatoire.")]
        #[Assert\Positive(message: "Le champ ligneId est invalide.")]
        public readonly ?int $ligneId = null,
        #[Assert\NotNull(message: "Le champ expectedVersion est obligatoire.")]
        #[Assert\PositiveOrZero(message: "Le champ expectedVersion est invalide.")]
        public readonly ?int $expectedVersion = null,
    ) {}
}
