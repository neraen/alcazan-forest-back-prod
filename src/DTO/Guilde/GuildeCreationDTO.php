<?php

namespace App\DTO\Guilde;

use App\Config\GuildeConfig;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fondation d'une guilde.
 *
 * Les bornes sont dupliquées ici (validation d'entrée) et dans `GuildeService` (règle
 * métier) : le DTO refuse un payload aberrant avant tout travail, le service reste vrai même
 * appelé autrement. Les deux lisent `GuildeConfig`, donc il n'y a qu'un chiffre.
 */
class GuildeCreationDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le nom de la guilde est obligatoire.")]
        #[Assert\Length(
            min: GuildeConfig::NOM_MIN,
            max: GuildeConfig::NOM_MAX,
            minMessage: "Le nom doit faire au moins {{ limit }} caractères.",
            maxMessage: "Le nom ne peut pas dépasser {{ limit }} caractères."
        )]
        public readonly ?string $nom = null,
        #[Assert\Length(max: GuildeConfig::DESCRIPTION_MAX, maxMessage: "La description est trop longue.")]
        public readonly ?string $description = null,
    ) {}
}
