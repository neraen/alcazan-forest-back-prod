<?php

namespace App\DTO\Quest;

use Symfony\Component\Validator\Constraints as Assert;

class QuestIdDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ questId est obligatoire.")]
        public readonly ?int $questId = null,
    ) {}
}
