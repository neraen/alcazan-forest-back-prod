<?php

namespace App\Enum;

/**
 * Types d'action de quête. Les valeurs sont stockées en base (action.action_type),
 * ne JAMAIS les renuméroter. BATTRE_MONSTRE, CHOIX et KILL_PVP sont réservés :
 * non proposés dans le QuestMaker et rejetés par QuestProgressionService
 * tant que leur vérification n'est pas implémentée.
 */
enum ActionType: int {
   case SCRIPTED_EFFECT = 1;
   case DONNER_OBJET = 2;
   case DONNER_OR = 3;
   case DONNER_EQUIPEMENT = 4;
   case DONNER_CONSOMMABLE = 5;
   case ATTEINDRE_LEVEL = 6;
   case PARLER_PNJ = 7;
   case BATTRE_BOSS = 8;
   case BATTRE_MONSTRE = 9;
   case CHOIX = 10;
   case PASSER_DIALOGUE = 11;
   case POSSEDER_OBJET = 12;
   case VISITER_CARTE = 13;
   case KILL_PVP = 14;

    /** L'action impose une condition vérifiée côté serveur avant d'avancer. */
    public function isCondition(): bool {
        return match ($this) {
            self::SCRIPTED_EFFECT, self::PASSER_DIALOGUE => false,
            default => true,
        };
    }

    /** Types utilisables aujourd'hui (vérification serveur existante). */
    public function isImplemented(): bool {
        return match ($this) {
            self::BATTRE_MONSTRE, self::CHOIX, self::KILL_PVP => false,
            default => true,
        };
    }
}
