<?php

namespace App\Enum;

/**
 * Types d'action de quête. Les valeurs sont stockées en base (action.action_type),
 * ne JAMAIS les renuméroter. KILL_PVP est réservé : non proposé dans le QuestMaker
 * et rejeté par QuestProgressionService tant que sa vérification n'est pas
 * implémentée.
 *
 * CHOIX = bouton de choix narratif pur (ni condition ni coût serveur, comme
 * PASSER_DIALOGUE) : sa seule raison d'être est de porter un branchement
 * (Action.nextSequence / endsQuest) vers une suite de quête différente.
 *
 * Trois types s'appuient sur les COMPTEURS de progression (`joueur_compteur`) :
 * BATTRE_MONSTRE, FABRIQUER_OBJET et RECOLTER_RESSOURCE. Ils se mesurent tous
 * DEPUIS l'entrée du joueur dans l'étape (`user_quete.compteurs_depart`), et non
 * depuis la création du personnage.
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
   case FABRIQUER_OBJET = 15;
   case RECOLTER_RESSOURCE = 16;

    /** L'action impose une condition vérifiée côté serveur avant d'avancer. */
    public function isCondition(): bool {
        return match ($this) {
            self::SCRIPTED_EFFECT, self::PASSER_DIALOGUE, self::CHOIX => false,
            default => true,
        };
    }

    /** Types utilisables aujourd'hui (vérification serveur existante). */
    public function isImplemented(): bool {
        return match ($this) {
            self::KILL_PVP => false,
            default => true,
        };
    }

    /**
     * Compteur de progression lu par ce type — null s'il n'en lit aucun.
     *
     * C'est le SEUL endroit qui associe un type d'action à un compteur : la condition,
     * le message de blocage, l'instantané de départ et l'affichage « 3 / 10 » en
     * dérivent tous, de sorte qu'ajouter un type comptable ne demande qu'un case ici.
     */
    public function compteur(): ?TypeCompteur {
        return match ($this) {
            self::BATTRE_MONSTRE => TypeCompteur::MONSTRE_TUE,
            self::FABRIQUER_OBJET => TypeCompteur::OBJET_FABRIQUE,
            self::RECOLTER_RESSOURCE => TypeCompteur::RESSOURCE_RECOLTEE,
            default => null,
        };
    }
}
