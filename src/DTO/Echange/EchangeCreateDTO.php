<?php

namespace App\DTO\Echange;

use Symfony\Component\Validator\Constraints as Assert;

class EchangeCreateDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ cibleId est obligatoire.")]
        #[Assert\Positive(message: "Le champ cibleId est invalide.")]
        public readonly ?int $cibleId = null,
    ) {}
}
