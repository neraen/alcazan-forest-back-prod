<?php

namespace App\Enum;

/**
 * Cycle de vie d'un lot déposé à l'hôtel des ventes.
 * EN_VENTE → (VENDUE | RETIREE | EXPIREE). Une fois close, une annonce ne rouvre jamais :
 * elle reste en base avec son objet déjà restitué ou transféré, comme audit du mouvement.
 */
enum StatutHotelVente: string
{
    case EN_VENTE = 'en_vente';
    case VENDUE = 'vendue';
    case RETIREE = 'retiree';
    case EXPIREE = 'expiree';

    public function estTerminal(): bool
    {
        return match ($this) {
            self::VENDUE, self::RETIREE, self::EXPIREE => true,
            self::EN_VENTE => false,
        };
    }

    /** Libellé affichable — le front ne doit connaître aucun statut en dur. */
    public function label(): string
    {
        return match ($this) {
            self::EN_VENTE => 'En vente',
            self::VENDUE => 'Vendue',
            self::RETIREE => 'Retirée',
            self::EXPIREE => 'Expirée',
        };
    }
}
