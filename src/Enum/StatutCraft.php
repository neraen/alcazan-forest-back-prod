<?php

namespace App\Enum;

/**
 * État d'une commande de fabrication. Les valeurs sont stockées : ne JAMAIS les renommer.
 *
 * ⚠️ « Prête » n'est PAS un statut. La disponibilité se DÉDUIT de `pretAt` face à
 * l'horloge serveur — même principe que le tick paresseux des donjons et que les
 * rechargements d'interaction. Un statut « prête » supposerait que quelqu'un le pose,
 * donc une tâche périodique, donc du travail pour des joueurs déconnectés.
 */
enum StatutCraft: string
{
    case EN_COURS = 'en_cours';
    case RETIREE = 'retiree';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::RETIREE => 'Retirée',
            self::ANNULEE => 'Annulée',
        };
    }
}
