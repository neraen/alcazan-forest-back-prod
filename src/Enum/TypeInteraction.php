<?php

namespace App\Enum;

/**
 * Nature d'un point interactif posé sur une case de carte. Les valeurs sont stockées
 * en base : ne JAMAIS les renommer.
 *
 * Le type ne détermine PAS ce que fait l'interaction (ça, c'est la récompense, l'effet
 * scripté et les conditions) : il qualifie le geste pour le joueur et l'affichage.
 * Ajouter « pêcher » ou « miner » = un case ici + un case dans InteractionConfig.
 */
enum TypeInteraction: string
{
    /** Ressource à ramasser : herbe, minerai, bois. Typiquement liée à un métier. */
    case RECOLTER = 'recolter';

    /** Coffre, cache, réserve. */
    case OUVRIR = 'ouvrir';

    /** Levier, mécanisme, stèle. */
    case ACTIONNER = 'actionner';

    /** Point d'entrée d'un effet scripté du jeu (auberge, butin de boss…). */
    case EFFET = 'effet';

    public function label(): string
    {
        return match ($this) {
            self::RECOLTER => 'Récolter',
            self::OUVRIR => 'Ouvrir',
            self::ACTIONNER => 'Actionner',
            self::EFFET => 'Effet scripté',
        };
    }

    /** Verbe affiché au joueur sur la case. */
    public function verbe(): string
    {
        return match ($this) {
            self::RECOLTER => 'Récolter',
            self::OUVRIR => 'Ouvrir',
            self::ACTIONNER => 'Actionner',
            self::EFFET => 'Utiliser',
        };
    }
}
