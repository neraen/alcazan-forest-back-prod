<?php

namespace App\Config;

/**
 * Curseurs du duel entre joueurs, en UN seul endroit — pendant de `JournalConfig`,
 * `ClassementConfig` et `GuildeConfig`.
 *
 * ## La formule d'honneur est CONTINUE, et c'est une correction
 *
 * L'ancienne version était une chaîne de six `if/else` sur des entiers, et elle avait des
 * trous : une différence de niveaux **entre 30 et 50**, ou **égale à 9, 18 ou 30**, tombait
 * dans le `else` final et rapportait **+50** — le maximum, pour avoir tué quelqu'un quarante
 * niveaux en dessous de soi. L'exact inverse de l'intention documentée en §2.
 *
 * *Une chaîne de branches sur des entiers aura toujours des trous.* Une droite bornée ne peut
 * pas en avoir : c'est la seule raison du changement de forme, pas un rééquilibrage.
 * Les valeurs restent des placeholders assumés (doc §7).
 */
final class PvpConfig
{
    /* ------------------------------------------------------------------ */
    /* Honneur                                                             */
    /* ------------------------------------------------------------------ */

    /** Honneur gagné pour une victoire à niveau ÉGAL. */
    public const HONNEUR_BASE = 20;

    /**
     * Ce que rapporte (ou coûte) chaque niveau d'écart.
     *
     * Positif : battre plus fort que soi rapporte plus. Le signe est ce qui décourage
     * d'écraser des débutants, et il ne dépend d'aucune branche.
     */
    public const HONNEUR_PAR_NIVEAU = 2;

    /** Bornes du gain du vainqueur. Le minimum est NÉGATIF : écraser un faible coûte. */
    public const HONNEUR_GAIN_MIN = -10;
    public const HONNEUR_GAIN_MAX = 50;

    /** Bornes de la perte du vaincu — toujours ≤ 0. */
    public const HONNEUR_PERTE_MIN = -25;
    public const HONNEUR_PERTE_MAX = 0;

    /** Plancher et plafond de `user.honneur`. */
    public const HONNEUR_PLANCHER = -1000;
    public const HONNEUR_PLAFOND = 1000;

    /**
     * Combien de temps une même victime ne rapporte plus rien.
     *
     * Sans elle, deux comptes complices se tuent en boucle et fabriquent de l'honneur à
     * volonté — le classement PvP ne mesurerait alors que la patience. La fenêtre est lue
     * dans `evenement_jeu` via l'index `(acteur_id, cree_le)`.
     *
     * ⚠️ `JournalConfig::RETENTION_JOURS` ne doit JAMAIS descendre sous cette fenêtre : la
     * purge effacerait les kills récents et rouvrirait le farm.
     */
    public const FENETRE_ANTI_FARM_HEURES = 6;

    /**
     * Le feu ami est AUTORISÉ : on peut frapper son propre camp — mais ça se paie.
     *
     * Interdire était le choix initial ; il rendait la trahison impossible plutôt que
     * coûteuse, ce qui n'est pas la même chose. Un allié frappé reste une décision de jeu :
     * elle doit avoir un prix, pas un mur.
     *
     * Le prix est double, et les deux moitiés ne mesurent pas la même chose :
     *  - l'**honneur** est la conduite en duel — frapper un allié la salit ;
     *  - le **karma** est la manière dont on se comporte dans le monde — trahir en est une.
     * Les faire tomber ensemble est ce qui donne enfin une conséquence de jeu à
     * `user.alignement` en dehors des guildes.
     */
    public const FEU_AMI_AUTORISE = true;

    /** Honneur perdu à CHAQUE coup porté à un allié. */
    public const FEU_AMI_HONNEUR_PAR_COUP = -2;

    /** Karma perdu à chaque coup porté à un allié. */
    public const FEU_AMI_KARMA_PAR_COUP = -1;

    /**
     * Honneur perdu en ACHEVANT un allié, en plus du coup lui-même.
     *
     * Nettement plus lourd que le plus gros gain possible face à un ennemi : tuer un allié ne
     * doit jamais pouvoir se rentabiliser, même en alternant les cibles.
     */
    public const FEU_AMI_HONNEUR_MISE_A_MORT = -60;

    /** Karma perdu en achevant un allié, en plus du coup. */
    public const FEU_AMI_KARMA_MISE_A_MORT = -15;

    /* ------------------------------------------------------------------ */
    /* Récompenses                                                         */
    /* ------------------------------------------------------------------ */

    public const XP_BASE = 200;
    public const XP_PAR_NIVEAU = 8;
    public const XP_MIN = 20;
    public const XP_MAX = 600;

    /** XP d'un soin porté à autrui. Fixe : rien ne mesure la « qualité » d'un soin. */
    public const XP_SOIN = 120;

    /* ------------------------------------------------------------------ */

    /** Honneur gagné par le vainqueur. Positif si la victime était plus forte. */
    public static function gainVainqueur(int $niveauVainqueur, int $niveauVaincu): int
    {
        return self::borner(
            self::HONNEUR_BASE + self::HONNEUR_PAR_NIVEAU * ($niveauVaincu - $niveauVainqueur),
            self::HONNEUR_GAIN_MIN,
            self::HONNEUR_GAIN_MAX
        );
    }

    /**
     * Honneur perdu par le vaincu — toujours négatif ou nul.
     *
     * Tomber face à plus fort que soi ne déshonore pas ; se faire battre par un débutant, si.
     */
    public static function perteVaincu(int $niveauVainqueur, int $niveauVaincu): int
    {
        return self::borner(
            -self::HONNEUR_BASE + self::HONNEUR_PAR_NIVEAU * ($niveauVaincu - $niveauVainqueur),
            self::HONNEUR_PERTE_MIN,
            self::HONNEUR_PERTE_MAX
        );
    }

    /** XP d'une victoire : plus la cible est forte, plus elle rapporte. */
    public static function experiencePour(int $niveauVainqueur, int $niveauVaincu): int
    {
        return self::borner(
            self::XP_BASE + self::XP_PAR_NIVEAU * ($niveauVaincu - $niveauVainqueur),
            self::XP_MIN,
            self::XP_MAX
        );
    }

    public static function borner(int|float $valeur, int $min, int $max): int
    {
        return (int) max($min, min($max, (int) round($valeur)));
    }
}
