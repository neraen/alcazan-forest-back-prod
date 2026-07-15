<?php

namespace App\DTO\Quest;

use Symfony\Component\Validator\Constraints as Assert;

class PnjInteractionDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ pnjId est obligatoire.")]
        public readonly ?int $pnjId = null,
    ) {}
}
