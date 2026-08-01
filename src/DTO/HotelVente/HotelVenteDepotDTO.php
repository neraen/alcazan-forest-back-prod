<?php

namespace App\DTO\HotelVente;

use App\Enum\TypeItem;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mise en vente d'un lot. Le client ne transmet PAS les frais de dépôt : le serveur les
 * recalcule depuis HotelVenteConfig, et ce qu'affichait l'écran n'engage rien.
 */
class HotelVenteDepotDTO
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
        #[Assert\NotBlank(message: "Le champ prix est obligatoire.")]
        #[Assert\Positive(message: "Le prix doit être supérieur à zéro.")]
        public readonly ?int $prix = null,
    ) {}
}
