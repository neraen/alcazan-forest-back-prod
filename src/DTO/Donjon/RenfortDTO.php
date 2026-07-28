<?php

namespace App\DTO\Donjon;

use Symfony\Component\Validator\Constraints as Assert;

class RenfortDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $renfortId = 0,
        #[Assert\Positive]
        public readonly int $spellId = 0,
    ) {}
}
