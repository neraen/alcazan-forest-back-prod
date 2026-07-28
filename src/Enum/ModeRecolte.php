<?php

namespace App\Enum;

/**
 * Manière dont le joueur prélève sur un gisement. Transporté dans le payload, jamais
 * stocké — ce qui persiste, ce sont ses CONSÉQUENCES (recharges et karma).
 *
 * Whitelist serveur : un mode inconnu est refusé, et un mode envoyé sur une case qui ne
 * propose pas le choix l'est aussi. Le client ne décide de rien, il exprime une intention.
 */
enum ModeRecolte: string
{
    /** Peu de matière, le gisement se refait vite, personne d'autre n'est lésé. */
    case ETHIQUE = 'ethique';

    /** Beaucoup de matière d'un coup, mais le gisement est saigné POUR TOUT LE MONDE. */
    case INTENSIVE = 'intensive';

    public function label(): string
    {
        return match ($this) {
            self::ETHIQUE => 'Récolte mesurée',
            self::INTENSIVE => 'Récolte intensive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ETHIQUE => "Vous ne prenez que ce qu'il faut. Le gisement se refait vite et reste ouvert aux autres.",
            self::INTENSIVE => "Vous raclez tout. Vous emportez beaucoup plus, mais l'endroit sera mort un long moment — pour vous comme pour les autres.",
        };
    }
}
