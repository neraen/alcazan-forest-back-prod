<?php

namespace App\DTO\Craft;

use App\Enum\ModeCraft;
use Symfony\Component\Validator\Constraints as Assert;

class CraftLancerDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $recetteId = 0,

        #[Assert\NotNull]
        public readonly ?ModeCraft $mode = null,
    ) {}
}
