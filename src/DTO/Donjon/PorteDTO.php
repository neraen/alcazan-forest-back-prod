<?php

namespace App\DTO\Donjon;

use Symfony\Component\Validator\Constraints as Assert;

/** Case de porte cliquée sur la carte (une case wrap qui vise une salle de donjon). */
class PorteDTO
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $carteCarreauId = 0,
    ) {}
}
