<?php

namespace App\DTO\Artisanat;

use Symfony\Component\Validator\Constraints as Assert;

class ArtisanatIdDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $id = 0,
    ) {}
}
