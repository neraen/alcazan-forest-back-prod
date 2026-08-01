<?php

namespace App\Exception;

/**
 * Le lot visé n'est plus achetable : un autre joueur l'a pris, le vendeur l'a retiré,
 * l'annonce a expiré, ou le prix affiché n'est plus celui de la base.
 *
 * Portée en HTTP 409 par HotelVenteController — et non en 400 — parce que ce n'est pas une
 * erreur du joueur mais un écran périmé : le front adopte l'état frais joint et recharge son
 * catalogue, exactement comme il le fait sur un conflit d'échange.
 */
class HotelVenteIndisponibleException extends \RuntimeException
{
    public function __construct(
        private readonly ?array $annonce,
        string $message = "Ce lot n'est plus disponible."
    ) {
        parent::__construct($message);
    }

    public function getAnnonce(): ?array
    {
        return $this->annonce;
    }
}
