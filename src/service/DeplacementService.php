<?php

namespace App\service;

use App\Entity\CarteCarreau;
use App\Entity\User;
use App\Exception\DeplacementException;

/**
 * Arbitre la LÉGALITÉ d'un pas sur la carte (`POST /api/joueur/case/update_position`).
 *
 * L'endpoint appliquait jusqu'ici n'importe quelle case envoyée par le client, pourvu
 * qu'elle soit libre : un client modifié se téléportait où il voulait, y compris dans un
 * décor infranchissable, et les PM devenaient négatifs. Les clients (`getUnabledMove` du
 * web, `calculerAccessibles` du mobile) n'appliquent ces règles que pour l'AFFICHAGE ;
 * c'est ici qu'elles font foi.
 *
 * Ce service ne juge pas l'occupation par un autre joueur : c'est une course légitime
 * entre deux clients honnêtes, que l'endpoint traite comme avant (pas de déplacement,
 * état frais renvoyé). Les passages (`is_wrap`) ne sont pas refusés non plus : le clavier
 * du web peut y poser le pied, le changement de carte passant par son propre endpoint.
 */
class DeplacementService
{
    /** Un pas = une case, diagonales comprises (distance de Tchebychev). */
    public const PORTEE_D_UN_PAS = 1;

    /** Coût d'un pas en points de mouvement. */
    public const COUT_D_UN_PAS = 1;

    /**
     * @throws DeplacementException si le pas est illégal
     */
    public function verifierPas(User $user, ?CarteCarreau $destination): void
    {
        if ($destination === null) {
            throw new DeplacementException("Cette case n'existe pas.");
        }

        $ecartAbscisse = abs((int)$destination->getAbscisse() - (int)$user->getCaseAbscisse());
        $ecartOrdonnee = abs((int)$destination->getOrdonnee() - (int)$user->getCaseOrdonnee());
        $distance = max($ecartAbscisse, $ecartOrdonnee);

        if ($distance === 0) {
            throw new DeplacementException("Vous êtes déjà sur cette case.");
        }
        if ($distance > self::PORTEE_D_UN_PAS) {
            throw new DeplacementException("Cette case est trop loin : on n'avance que d'une case à la fois.");
        }
        if (!$destination->getIsUsable()) {
            throw new DeplacementException("Un obstacle vous empêche d'aller à cet endroit.");
        }
        if ($destination->getPnj() !== null) {
            throw new DeplacementException("Quelqu'un se tient déjà sur cette case.");
        }
        if ((int)$user->getMouvementPoint() < self::COUT_D_UN_PAS) {
            throw new DeplacementException("Vous n'avez plus de points de mouvement.");
        }
    }
}
