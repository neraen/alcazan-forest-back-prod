<?php

namespace App\Enum;

/**
 * Condition à remplir pour ENTRER dans une salle de donjon. Évaluée sur la salle qu'on
 * QUITTE : « pour passer dans la salle 3, il faut avoir nettoyé la salle 2 ».
 *
 * Les valeurs sont stockées en base : ne JAMAIS les renommer.
 *
 * Une porte ouverte le reste pour l'instance (`donjon_instance_salle.ouverte`) : on ne
 * refait pas l'énigme à chaque aller-retour, et un joueur qui revient sur ses pas n'est
 * jamais enfermé.
 */
enum ConditionSalleDonjon: string
{
    /** Passage libre (comportement historique). */
    case AUCUNE = 'aucune';

    /** Tous les monstres de la salle précédente doivent être tombés. */
    case SALLE_NETTOYEE = 'salle_nettoyee';

    /**
     * Leviers de la salle précédente actionnés par des joueurs DIFFÉRENTS dans une même
     * fenêtre de temps — la seule condition qui exige réellement d'être plusieurs.
     * params : {leviers: int, fenetreSecondes: int}
     */
    case LEVIERS = 'leviers';

    /** Le boss de l'instance doit être vaincu (salle au trésor). */
    case BOSS_VAINCU = 'boss_vaincu';

    public function label(): string
    {
        return match ($this) {
            self::AUCUNE => 'Aucune (passage libre)',
            self::SALLE_NETTOYEE => 'Avoir nettoyé la salle précédente',
            self::LEVIERS => 'Énigme à leviers',
            self::BOSS_VAINCU => 'Avoir vaincu le boss',
        };
    }

    /** Message montré au joueur quand la condition n'est pas remplie. */
    public function refus(): string
    {
        return match ($this) {
            self::AUCUNE => '',
            self::SALLE_NETTOYEE => "La voie est barrée tant que des créatures rôdent derrière vous.",
            self::LEVIERS => "Le passage est scellé. Des leviers commandent cette porte.",
            self::BOSS_VAINCU => "Vous devez terrasser le gardien avant d'aller plus loin.",
        };
    }

    public function parametres(): array
    {
        return match ($this) {
            self::LEVIERS => ['leviers' => 2, 'fenetreSecondes' => 15],
            default => [],
        };
    }
}
