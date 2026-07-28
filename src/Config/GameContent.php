<?php

namespace App\Config;

/**
 * Ids du contenu de jeu référencés par le code (lignes en base, volume Docker).
 * Centralisés ici pour ne plus être éparpillés en dur dans les contrôleurs/services.
 * Si le contenu change en base, c'est ICI qu'il faut mettre à jour les ids.
 */
final class GameContent
{
    /** Carte de départ des nouveaux personnages */
    public const SPAWN_MAP_ID = 2;
    public const SPAWN_ABSCISSE = 9;
    public const SPAWN_ORDONNEE = 9;

    /** Classe par défaut avant le choix scénarisé */
    public const DEFAULT_CLASSE_ID = 3;

    /** Référentiel des 6 caractéristiques (force, intelligence, … armure, constitution) */
    public const CARACTERISTIQUE_IDS = [1, 2, 3, 4, 5, 6];

    /** Équipements de départ donnés lors du choix de classe */
    public const STARTING_EQUIPEMENT_ARCHER = 2;
    public const STARTING_EQUIPEMENT_GUERRIER = 22;
    public const STARTING_EQUIPEMENT_SORCIER = 23;
    public const STARTING_EQUIPEMENT_MOINE = 24;

    /** Type de terrain par défaut des cartes vierges (MapMaker) */
    public const DEFAULT_CARREAU_ID = 1;

    /** Wrap par défaut posé par le MapMaker */
    public const DEFAULT_WRAP_ID = 1;

    /**
     * Durée pendant laquelle la salle au trésor reste ouverte après la mise à mort
     * du boss (wrap de condition `boss`). Sera portée par le donjon lui-même quand
     * le DonjonMaker existera.
     */
    public const FENETRE_SALLE_TRESOR_SECONDES = 3 * 3600;
}
