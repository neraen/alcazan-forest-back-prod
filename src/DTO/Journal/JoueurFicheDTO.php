<?php

namespace App\DTO\Journal;

use Symfony\Component\Validator\Constraints as Assert;

/** Le joueur dont on demande la fiche d'enquête. */
class JoueurFicheDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ userId est obligatoire.")]
        #[Assert\Positive(message: "Le champ userId est invalide.")]
        public readonly ?int $userId = null,
    ) {}
}
