<?php

namespace App\Enum;

/**
 * Cycle de vie d'une session d'échange joueur-à-joueur.
 * EN_ATTENTE → OUVERT → (COMPLETE | ANNULE | EXPIRE) ; EN_ATTENTE peut aussi finir
 * directement ANNULE (refus) ou EXPIRE.
 */
enum StatutEchange: string
{
    case EN_ATTENTE = 'en_attente';
    case OUVERT = 'ouvert';
    case COMPLETE = 'complete';
    case ANNULE = 'annule';
    case EXPIRE = 'expire';

    public function estTerminal(): bool
    {
        return match ($this) {
            self::COMPLETE, self::ANNULE, self::EXPIRE => true,
            self::EN_ATTENTE, self::OUVERT => false,
        };
    }
}
