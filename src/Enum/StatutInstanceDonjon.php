<?php

namespace App\Enum;

/**
 * Cycle de vie d'une instance de donjon.
 * EN_COURS → (TERMINEE quand le boss tombe | ABANDONNEE quand tout le monde sort
 * | EXPIREE au-delà de la durée max, constatée paresseusement).
 *
 * Une instance terminale n'est plus jouable, mais le VERROU du joueur continue de
 * la référencer jusqu'au reset quotidien : c'est ce qui interdit d'en obtenir une
 * neuve dans la journée tout en autorisant le retour dans la sienne.
 */
enum StatutInstanceDonjon: string
{
    case EN_COURS = 'en_cours';
    case TERMINEE = 'terminee';
    case ABANDONNEE = 'abandonnee';
    case EXPIREE = 'expiree';

    public function estTerminal(): bool
    {
        return match ($this) {
            self::TERMINEE, self::ABANDONNEE, self::EXPIREE => true,
            self::EN_COURS => false,
        };
    }
}
