<?php

namespace App\DTO\Echange;

use Symfony\Component\Validator\Constraints as Assert;

class EchangeOrDTO
{
    public function __construct(
        #[Assert\NotNull(message: "Le champ montant est obligatoire.")]
        #[Assert\PositiveOrZero(message: "Le montant ne peut pas être négatif.")]
        public readonly ?int $montant = null,
        #[Assert\NotNull(message: "Le champ expectedVersion est obligatoire.")]
        #[Assert\PositiveOrZero(message: "Le champ expectedVersion est invalide.")]
        public readonly ?int $expectedVersion = null,
    ) {}
}
