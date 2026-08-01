<?php

namespace App\DTO\HotelVente;

use App\Enum\TriHotelVente;
use App\Enum\TypeItem;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filtres du catalogue. Tout est optionnel : ouvrir l'hôtel sans rien préciser doit marcher.
 *
 * `type` et `tri` sont des enums et non des chaînes libres — les deux finissent dans une
 * requête (un `WHERE` et un `ORDER BY`), ce n'est pas la place d'une valeur venue du client.
 */
class HotelVenteCatalogueDTO
{
    public function __construct(
        public readonly ?TypeItem $type = null,
        #[Assert\Length(max: 100, maxMessage: "Recherche trop longue.")]
        public readonly ?string $recherche = null,
        public readonly ?TriHotelVente $tri = null,
        #[Assert\Positive(message: "Le numéro de page est invalide.")]
        public readonly ?int $page = null,
    ) {}

    public function tri(): TriHotelVente
    {
        return $this->tri ?? TriHotelVente::RECENT;
    }

    public function page(): int
    {
        return max(1, $this->page ?? 1);
    }
}
