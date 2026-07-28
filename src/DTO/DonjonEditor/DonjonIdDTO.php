<?php

namespace App\DTO\DonjonEditor;

use Symfony\Component\Validator\Constraints as Assert;

class DonjonIdDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $donjonId = 0,
    ) {}
}
