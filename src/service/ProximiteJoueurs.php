<?php

namespace App\service;

use App\Entity\User;

/**
 * Distance entre deux joueurs sur la grille (même règle que l'adjacence aux PNJ :
 * distance de Tchebychev sur `caseAbscisse`/`caseOrdonnee`, cartes identiques).
 * Le serveur ne faisait AUCUN contrôle de proximité entre joueurs avant l'échange —
 * ne jamais se fier à la distance calculée côté client.
 */
final class ProximiteJoueurs
{
    public static function sontProches(User $premier, User $second, int $rayon): bool
    {
        if ($premier->getMap() === null || $second->getMap() === null) {
            return false;
        }

        return $premier->getMap()->getId() === $second->getMap()->getId()
            && abs($premier->getCaseAbscisse() - $second->getCaseAbscisse()) <= $rayon
            && abs($premier->getCaseOrdonnee() - $second->getCaseOrdonnee()) <= $rayon;
    }
}
