<?php

namespace App\Enum;

/**
 * Mécaniques de combat de boss, configurables par donjon (table `donjon_mecanique`).
 * Les valeurs sont stockées en base : ne JAMAIS les renommer.
 *
 * Chaque mécanique est bornée par une fenêtre de vie du boss (vieMax/vieMin en %),
 * ce qui donne les « phases » sans table supplémentaire : à 100-75 % le boss fait
 * une chose, à 25-0 % une autre. C'est le même découpage que `boss_sortilege`.
 */
enum MecaniqueDonjon: string
{
    /**
     * Le boss annonce des cases, qui frappent au tick suivant. C'est la mécanique qui
     * rend le DÉPLACEMENT décisif : sans elle, la position n'a aucune importance.
     * params : {rayon: int, degats: int, delaiSecondes: int}
     */
    case ZONE_TELEGRAPHIEE = 'zone_telegraphiee';

    /**
     * Renforts qui apparaissent dans la salle du boss et qu'il faut gérer en parallèle.
     * params : {monstreId: int, quantite: int}
     */
    case ADDS = 'adds';

    /**
     * Au-delà de `apresSecondes` de combat, les dégâts du boss sont multipliés :
     * impose un DPS minimum et donne un rythme à la rencontre.
     * params : {apresSecondes: int, multiplicateur: float}
     */
    case ENRAGE = 'enrage';

    /**
     * Leviers à actionner par des joueurs DIFFÉRENTS dans une fenêtre de temps :
     * la seule mécanique qui exige explicitement de se répartir dans la salle.
     * params : {leviers: int, fenetreSecondes: int, degatsBoss: int}
     */
    case ENIGME_LEVIERS = 'enigme_leviers';

    public function label(): string
    {
        return match ($this) {
            self::ZONE_TELEGRAPHIEE => 'Zone télégraphiée',
            self::ADDS => 'Renforts',
            self::ENRAGE => 'Enrage',
            self::ENIGME_LEVIERS => 'Énigme à leviers',
        };
    }

    /** Paramètres attendus, pour la validation et le futur DonjonMaker. */
    public function parametres(): array
    {
        return match ($this) {
            self::ZONE_TELEGRAPHIEE => ['rayon' => 1, 'degats' => 200, 'delaiSecondes' => 10],
            self::ADDS => ['monstreId' => null, 'quantite' => 2],
            self::ENRAGE => ['apresSecondes' => 600, 'multiplicateur' => 2.0],
            self::ENIGME_LEVIERS => ['leviers' => 2, 'fenetreSecondes' => 15, 'degatsBoss' => 500],
        };
    }
}
