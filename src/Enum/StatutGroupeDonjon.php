<?php

namespace App\Enum;

/**
 * Cycle de vie d'un groupe de donjon (le « lobby » ouvert devant la porte).
 * OUVERT → (LANCE quand le meneur fait entrer le groupe | ANNULE | EXPIRE).
 *
 * Le groupe est ÉPHÉMÈRE et propre au donjon : il ne survit pas au lancement et
 * n'a aucune existence dans le reste du jeu (il n'y a pas de système de groupe global).
 */
enum StatutGroupeDonjon: string
{
    case OUVERT = 'ouvert';
    case LANCE = 'lance';
    case ANNULE = 'annule';
    case EXPIRE = 'expire';

    public function estTerminal(): bool
    {
        return match ($this) {
            self::LANCE, self::ANNULE, self::EXPIRE => true,
            self::OUVERT => false,
        };
    }
}
