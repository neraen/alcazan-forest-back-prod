<?php

namespace App\DTO\Metier;

use Symfony\Component\Validator\Constraints as Assert;

class MetierIdDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $metierId = 0,
    ) {}
}
