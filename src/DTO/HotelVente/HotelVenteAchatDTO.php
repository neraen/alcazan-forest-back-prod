<?php

namespace App\DTO\HotelVente;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Achat d'un lot. `prixAttendu` est la garde d'écran périmé, équivalente à l'`expectedVersion`
 * de l'échange : c'est le prix que le joueur avait sous les yeux. Le serveur ne débite jamais
 * cette valeur — il fait foi sur la base — mais un écart vaut 409 plutôt qu'un achat surprise.
 */
class HotelVenteAchatDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ annonceId est obligatoire.")]
        #[Assert\Positive(message: "Le champ annonceId est invalide.")]
        public readonly ?int $annonceId = null,
        #[Assert\NotBlank(message: "Le champ prixAttendu est obligatoire.")]
        #[Assert\Positive(message: "Le champ prixAttendu est invalide.")]
        public readonly ?int $prixAttendu = null,
    ) {}
}
