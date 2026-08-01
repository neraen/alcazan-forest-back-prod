<?php

namespace App\Enum;

/**
 * Le rayon sous lequel un événement se range dans un flux.
 *
 * Elle n'est PAS stockée en base : c'est `TypeEvenement::categorie()` qui la dérive.
 * Stocker la catégorie à côté du type créerait deux vérités à réconcilier le jour où
 * un événement changerait de rayon, alors que le type, lui, ne bouge jamais.
 *
 * C'est ce qui permet enfin les catégories d'historique que la refonte avait refusé
 * d'inventer faute de typage côté back (voir `docs/REFONTE_PLAN.md`, phase 6).
 */
enum CategorieEvenement: string
{
    case COMBAT = 'combat';
    case ECONOMIE = 'economie';
    case PROGRESSION = 'progression';
    case SOCIAL = 'social';
    case SYSTEME = 'systeme';

    public function label(): string
    {
        return match ($this) {
            self::COMBAT => 'Combat',
            self::ECONOMIE => 'Économie',
            self::PROGRESSION => 'Progression',
            self::SOCIAL => 'Social',
            self::SYSTEME => 'Système',
        };
    }
}
