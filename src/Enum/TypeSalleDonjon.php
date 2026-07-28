<?php

namespace App\Enum;

/**
 * Rôle d'une carte dans un donjon. L'ordre de traversée est porté par
 * DonjonSalle.ordre seul (comme Sequence.position pour les quêtes) ; le type ne
 * sert qu'à qualifier la salle pour les règles et l'affichage.
 */
enum TypeSalleDonjon: string
{
    /** Première salle : c'est elle qui déclenche la création de l'instance. */
    case ENTREE = 'entree';
    case COULOIR = 'couloir';
    case BOSS = 'boss';
    /** Salle au trésor, ouverte par la mise à mort du boss. */
    case TRESOR = 'tresor';

    public function label(): string
    {
        return match ($this) {
            self::ENTREE => "Entrée",
            self::COULOIR => "Couloir",
            self::BOSS => "Salle du boss",
            self::TRESOR => "Salle au trésor",
        };
    }
}
