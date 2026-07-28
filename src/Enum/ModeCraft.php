<?php

namespace App\Enum;

/**
 * Manière de mener une fabrication. Stocké sur la commande : le mode choisi au lancement
 * décide du temps ET de ce qui sera rendu au retrait, donc il doit survivre à l'attente.
 */
enum ModeCraft: string
{
    /** Temps normal, une part des ingrédients revient, karma positif. */
    case RECYCLAGE = 'recyclage';

    /** Temps fortement réduit, rien n'est récupéré, karma négatif. */
    case RAPIDE = 'rapide';

    public function label(): string
    {
        return match ($this) {
            self::RECYCLAGE => 'Fabrication soignée',
            self::RAPIDE => 'Fabrication expéditive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RECYCLAGE => "Vous prenez le temps de récupérer les chutes. Une part des ingrédients revient au sac.",
            self::RAPIDE => "Vous bâclez pour aller vite. Tout ce qui n'entre pas dans l'objet est perdu.",
        };
    }
}
