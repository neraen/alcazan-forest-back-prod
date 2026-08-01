<?php

namespace App\Enum;

/**
 * Où en est un joueur vis-à-vis d'une guilde. Valeurs stockées (`joueur_guilde.statut`).
 *
 * Une ligne `joueur_guilde` par joueur, JAMAIS deux : il est candidat quelque part OU membre
 * quelque part. La multi-candidature obligerait à répondre à « les autres candidatures
 * sont-elles refusées automatiquement à l'acceptation ? » — de la machine à états en plus
 * pour zéro gameplay. L'index UNIQUE `(user_id)` rend la règle exécutable.
 */
enum StatutGuilde: string
{
    case CANDIDAT = 'candidat';
    case MEMBRE = 'membre';

    public function label(): string
    {
        return match ($this) {
            self::CANDIDAT => 'Candidat',
            self::MEMBRE => 'Membre',
        };
    }
}
