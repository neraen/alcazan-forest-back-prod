<?php

namespace App\service;

use App\Entity\User;
use App\Enum\TypeCompteur;
use App\Repository\CompteurJoueurRepository;

/**
 * UNIQUE point de mutation des compteurs de progression (`joueur_compteur`).
 * Ne flushe pas et n'ouvre pas de transaction : l'appelant fournit la transaction,
 * même contrat que SacService, RecompenseService, MetierService et KarmaService.
 *
 * Un compteur répond à une seule question : « combien de fois ce joueur a-t-il
 * fait ça ? ». Il est **cumulatif et jamais remis à zéro** — c'est un fait de
 * partie, pas un état de quête. Ce sont les quêtes qui, elles, mémorisent où en
 * était le compteur quand le joueur a reçu l'étape (`user_quete.compteurs_depart`),
 * pour que « tuer 5 loups » veuille dire cinq loups DEPUIS la demande et non
 * cinq loups depuis la création du personnage. Sans cette séparation, un vétéran
 * validerait instantanément toutes les quêtes de chasse du jeu.
 *
 * Les événements comptés sont produits ailleurs — DeathService, CraftService,
 * InteractionService — et ces services ne connaissent QUE ce point d'entrée.
 */
class CompteurJoueurService
{
    public function __construct(private readonly CompteurJoueurRepository $repository) {}

    /**
     * Compte `$pas` occurrences de plus et renvoie la valeur atteinte.
     * Un pas nul ou négatif est ignoré : un compteur ne redescend jamais.
     */
    public function incrementer(User $user, TypeCompteur $type, int $cibleId, int $pas = 1): int
    {
        if ($cibleId <= 0 || $pas <= 0) {
            return $this->valeur($user, $type, $cibleId);
        }

        return $this->repository->incrementer($user, $type, $cibleId, $pas);
    }

    /** Combien de fois le joueur a fait ça (0 s'il ne l'a jamais fait). */
    public function valeur(User $user, TypeCompteur $type, int $cibleId): int
    {
        if ($cibleId <= 0) {
            return 0;
        }

        return $this->repository->valeur($user, $type, $cibleId);
    }

    /**
     * Progression depuis un instantané de départ, bornée à zéro.
     *
     * Un départ postérieur à la valeur courante n'est pas une anomalie de code : il
     * suffit que l'administrateur ait rebranché l'action sur une autre cible entre
     * l'instantané et la relecture. Renvoyer un négatif afficherait « -3 / 10 ».
     */
    public function progression(User $user, TypeCompteur $type, int $cibleId, int $depart): int
    {
        return max(0, $this->valeur($user, $type, $cibleId) - max(0, $depart));
    }
}
