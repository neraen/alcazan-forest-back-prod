<?php

namespace App\Enum;
enum ConditionalAction: int {
    case DONNER_OBJET = 2;
    case DONNER_OR = 3;
    case DONNER_EQUIPEMENT = 4;
    case DONNER_CONSOMMABLE = 5;
    case ATTEINDRE_LEVEL = 6;
    case PARLER_PNJ = 7;
    case BATTRE_BOSS = 8;
    case BATTRE_MONSTRE = 9;
    case CHOIX = 10;
    case POSSEDER_OBJET = 12;
    case VISITER_CARTE = 13;
    case KILL_PVP = 14;

   public static function getValues(){
       return array_column(self::cases(), 'value');
   }
}