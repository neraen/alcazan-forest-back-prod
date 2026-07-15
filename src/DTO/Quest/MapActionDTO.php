<?php

namespace App\DTO\Quest;

use Symfony\Component\Validator\Constraints as Assert;

class MapActionDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ actionId est obligatoire.")]
        public readonly ?int $actionId = null,
    ) {}
}
