<?php

namespace App\DTO\Craft;

use Symfony\Component\Validator\Constraints as Assert;

class CraftCommandeDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $commandeId = 0,
    ) {}
}
