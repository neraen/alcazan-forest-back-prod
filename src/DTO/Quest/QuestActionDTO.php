<?php

namespace App\DTO\Quest;

use Symfony\Component\Validator\Constraints as Assert;

class QuestActionDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ sequenceId est obligatoire.")]
        public readonly ?int $sequenceId = null,
        #[Assert\NotBlank(message: "Le champ actionId est obligatoire.")]
        public readonly ?int $actionId = null,
    ) {}
}
