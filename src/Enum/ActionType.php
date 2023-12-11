<?php

namespace App\Enum;
enum ActionType: int {
   case JSON = 1;
   case DONNER_OBJET = 2;
   case DONNER_EQUIPEMENT = 3;
   case DONNER_OR = 4;
   case POSSEDER_OBJET = 5;
   case BATTRE_BOSS = 6;
   case ATTEINDRE_LEVEL = 7;
   case PARLER_PNJ = 8;
   case VISITER_CARTE = 9;
   case BATTRE_MONSTRE = 10;
   case DONNER_CONSOMMABLE = 13;
   case KILL_PVP = 14;
}