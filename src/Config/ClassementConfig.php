<?php

namespace App\Config;

/**
 * Curseurs des classements, en UN seul endroit — pendant de `JournalConfig` et `CraftConfig`.
 * Aucun chiffre en dur côté client.
 */
final class ClassementConfig
{
    /**
     * Combien d'entrées le podium affiche.
     *
     * Volontairement borné et non paginé : un classement se consulte pour savoir qui domine
     * et où l'on se situe, pas pour parcourir le trombinoscope du serveur. Le rang personnel
     * est servi à part (`/api/classement/moi`), donc un joueur hors du top voit quand même
     * sa position — c'est ce qui rend la pagination inutile.
     */
    public const TAILLE_TOP = 50;

    /**
     * Les classements sont calculés À LA VOLÉE, sans table de snapshot.
     *
     * Justification, parce que ce choix se lirait sinon comme de la négligence : la
     * volumétrie est de quelques comptes, et `SELECT … WHERE cle = ? ORDER BY valeur DESC
     * LIMIT 50` sur l'index `(cle, valeur)` est un parcours d'index borné. Matérialiser
     * aujourd'hui ajouterait une table, une commande de scheduler, une fenêtre de fraîcheur
     * et un mode de panne (« le classement est figé depuis trois jours ») pour un gain non
     * mesurable.
     *
     * Le jour où ça ne tient plus — de l'ordre de quelques milliers de joueurs actifs, ou
     * l'ajout d'un agrégat non indexable — la sortie est déjà en place : TOUTES les lectures
     * passent par `ClassementService::top()`. Matérialiser revient alors à créer une table,
     * écrire une commande, et changer le corps d'UNE méthode. Zéro impact front.
     */
    public const CALCUL_A_LA_VOLEE = true;
}
