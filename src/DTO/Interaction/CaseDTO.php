<?php

namespace App\DTO\Interaction;

use App\Enum\ModeRecolte;
use Symfony\Component\Validator\Constraints as Assert;

class CaseDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $carteCarreauId = 0,

        /**
         * Manière de prélever, sur les seules cases qui proposent le choix. Absent =
         * comportement historique : toutes les cases posées avant le lot 2 continuent
         * de se comporter à l'identique.
         */
        #[Assert\Choice(callback: [ModeRecolte::class, 'cases'])]
        public readonly ?ModeRecolte $mode = null,
    ) {}
}
