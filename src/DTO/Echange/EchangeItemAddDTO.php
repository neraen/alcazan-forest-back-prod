<?php

namespace App\DTO\Echange;

use App\Enum\TypeItem;
use Symfony\Component\Validator\Constraints as Assert;

class EchangeItemAddDTO
{
    public function __construct(
        #[Assert\NotNull(message: "Le champ type est obligatoire.")]
        public readonly ?TypeItem $type = null,
        #[Assert\NotBlank(message: "Le champ itemId est obligatoire.")]
        #[Assert\Positive(message: "Le champ itemId est invalide.")]
        public readonly ?int $itemId = null,
        #[Assert\NotBlank(message: "Le champ quantite est obligatoire.")]
        #[Assert\Positive(message: "La quantité doit être supérieure à zéro.")]
        public readonly ?int $quantite = null,
        #[Assert\NotNull(message: "Le champ expectedVersion est obligatoire.")]
        #[Assert\PositiveOrZero(message: "Le champ expectedVersion est invalide.")]
        public readonly ?int $expectedVersion = null,
    ) {}
}
