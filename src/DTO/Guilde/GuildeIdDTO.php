<?php

namespace App\DTO\Guilde;

use Symfony\Component\Validator\Constraints as Assert;

/** La guilde visée par une candidature. */
class GuildeIdDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ guildeId est obligatoire.")]
        #[Assert\Positive(message: "Le champ guildeId est invalide.")]
        public readonly ?int $guildeId = null,
    ) {}
}
