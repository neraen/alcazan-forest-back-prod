<?php

namespace App\DTO\Interaction;

use Symfony\Component\Validator\Constraints as Assert;

class InteractionIdDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $interactionId = 0,
    ) {}
}
