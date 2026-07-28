<?php

namespace App\DTO\Donjon;

use Symfony\Component\Validator\Constraints as Assert;

class GroupeDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $groupeId = 0,
    ) {}
}
