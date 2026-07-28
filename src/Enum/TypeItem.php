<?php

namespace App\Enum;

/**
 * Les trois familles d'items que porte un inventaire. Les valeurs sont celles employées par le
 * front (`itemUtils.js` : `cat`) et transitent telles quelles dans les payloads d'API.
 */
enum TypeItem: string
{
    case EQUIPEMENT = 'equipement';
    case CONSOMMABLE = 'consommable';
    case OBJET = 'objet';
}
