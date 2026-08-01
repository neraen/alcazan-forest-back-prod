<?php

namespace App\Config;

/**
 * Les curseurs du journal d'événements, en UN seul endroit — pendant de `CraftConfig`
 * et `RecolteConfig`. Aucun chiffre en dur ailleurs, ni côté back ni côté front.
 */
final class JournalConfig
{
    /**
     * Au-delà, un événement n'a plus de valeur d'enquête et coûte de l'espace.
     *
     * Dimensionnement : ~20 joueurs actifs × ~500 événements/jour ≈ 10 000 lignes/jour,
     * soit ~900 000 lignes et quelques centaines de méga-octets à 90 jours. MySQL 8 ne
     * bronche pas. Le seuil où ça devient un vrai problème est de l'ordre de la dizaine
     * de millions de lignes, et la réponse à ce moment-là n'est PAS une rétention plus
     * courte mais un partitionnement par mois ou une table d'archive.
     *
     * ⚠️ Cette valeur ne doit JAMAIS descendre sous la fenêtre anti-farm du PvP (lot 6) :
     * l'honneur lit le journal pour savoir si l'attaquant a déjà tué cette victime
     * récemment. C'est le seul endroit où le journal est une entrée de gameplay, et il
     * impose donc un plancher à la rétention.
     */
    public const RETENTION_JOURS = 90;

    /** Taille d'un lot de suppression : voir `EvenementJeuRepository::supprimerAvant()`. */
    public const LOT_PURGE = 5000;

    public const PAGE_PAR_DEFAUT = 50;

    public const PAGE_MAX = 200;

    /**
     * Combien de lignes le journal d'un joueur remonte.
     *
     * Borné et non paginé : l'écran regroupe par jour et se parcourt, il ne s'explore pas.
     * L'ancien endpoint ne posait AUCUNE limite et renvoyait tout l'historique du personnage.
     */
    public const JOURNAL_JOUEUR_MAX = 200;

    /**
     * Une seule ligne `CONNEXION` par joueur et par JOUR CIVIL (voir `ConnexionSubscriber`).
     *
     * Ce qu'on veut mesurer est « qui a joué ce jour-là », pas « combien de fois le client
     * a renouvelé son jeton » — un jeton se redemande à chaque rechargement de page.
     */
    public const CONNEXION_UNE_PAR_JOUR = true;
}
