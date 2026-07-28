<?php

namespace App\Enum;

/**
 * Ressources réservables d'un joueur : les trois familles d'items (mêmes valeurs que TypeItem)
 * plus l'or. Utilisé par ReservationRessource / SacService.
 */
enum TypeRessource: string
{
    case EQUIPEMENT = 'equipement';
    case CONSOMMABLE = 'consommable';
    case OBJET = 'objet';
    case OR = 'or';

    public static function fromTypeItem(TypeItem $type): self
    {
        return self::from($type->value);
    }
}
