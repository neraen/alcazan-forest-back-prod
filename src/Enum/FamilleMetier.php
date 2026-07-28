<?php

namespace App\Enum;

/**
 * Famille d'un métier. Les valeurs sont stockées en base : ne JAMAIS les renommer.
 *
 * Sans famille, le plafond « 2 métiers de récolte, 3 de craft » n'est pas calculable :
 * c'est la seule raison d'être de cet enum, et c'est suffisant. Un métier de récolte
 * alimente le sac depuis le monde (cases interactives), un métier de craft transforme
 * ce que le sac contient.
 */
enum FamilleMetier: string
{
    /** Mineur, herboriste, bûcheron, dépeceur… */
    case RECOLTE = 'recolte';

    /** Alchimiste, forgeron, couturier, bijoutier, armurier, cuisinier, tanneur… */
    case CRAFT = 'craft';

    public function label(): string
    {
        return match ($this) {
            self::RECOLTE => 'Récolte',
            self::CRAFT => 'Fabrication',
        };
    }
}
