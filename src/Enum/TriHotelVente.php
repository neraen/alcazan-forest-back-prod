<?php

namespace App\Enum;

/**
 * Ordres de tri proposés au catalogue de l'hôtel des ventes.
 *
 * Une enum plutôt qu'une chaîne libre : le tri finit dans un `ORDER BY`, et une valeur
 * venue du client n'a rien à faire à cet endroit-là d'une requête.
 *
 * Pas de tri par nom : le nom de l'item n'est pas sur l'annonce (`item_id` est un entier
 * nu, sans FK), il faudrait le résoudre en PHP pour trier, ce qui interdirait la pagination.
 */
enum TriHotelVente: string
{
    case PRIX_CROISSANT = 'prix_croissant';
    case PRIX_DECROISSANT = 'prix_decroissant';
    case RECENT = 'recent';
    case EXPIRATION_PROCHE = 'expiration_proche';

    /** @return array{0: string, 1: string} champ de l'entité, sens */
    public function ordre(): array
    {
        return match ($this) {
            self::PRIX_CROISSANT => ['prix', 'ASC'],
            self::PRIX_DECROISSANT => ['prix', 'DESC'],
            self::RECENT => ['createdAt', 'DESC'],
            self::EXPIRATION_PROCHE => ['expiresAt', 'ASC'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PRIX_CROISSANT => 'Prix croissant',
            self::PRIX_DECROISSANT => 'Prix décroissant',
            self::RECENT => 'Plus récentes',
            self::EXPIRATION_PROCHE => 'Bientôt expirées',
        };
    }

    /** @return array<string, string> valeur => libellé, pour le select du front. */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $tri) {
            $options[$tri->value] = $tri->label();
        }

        return $options;
    }
}
