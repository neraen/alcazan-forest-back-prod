<?php

namespace App\DTO\HotelVente;

use Symfony\Component\Validator\Constraints as Assert;

class HotelVenteIdDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ annonceId est obligatoire.")]
        #[Assert\Positive(message: "Le champ annonceId est invalide.")]
        public readonly ?int $annonceId = null,
    ) {}
}
