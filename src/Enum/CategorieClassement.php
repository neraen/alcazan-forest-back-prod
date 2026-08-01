<?php

namespace App\Enum;

/**
 * Ce sur quoi les joueurs peuvent être classés.
 *
 * Elle est SÉPARÉE de `TypeCumul` parce qu'un classement et un cumul ne sont pas la même
 * chose : deux des cinq catégories ne sont PAS des cumuls mais des états courants
 * (`user.money`, `user.honneur`), et à l'inverse tous les cumuls ne méritent pas un podium
 * (« morts », « or dépensé » n'ont aucun intérêt en compétition). Les fusionner obligerait à
 * marquer d'un drapeau « classable » les uns et à inventer de faux cumuls pour les autres.
 *
 * C'est aussi ce qui permettra d'ajouter le classement des guildes au lot 5 sans toucher à
 * `TypeCumul` — une guilde n'a pas de ligne dans `joueur_cumul`.
 *
 * Le front ne connaît AUCUNE catégorie en dur : il lit `ClassementService::categories()`.
 * Ajouter un classement reste donc une modification back seulement.
 */
enum CategorieClassement: string
{
    case XP_TOTALE = 'xp_totale';
    case MONSTRES_TUES = 'monstres_tues';
    case BOSS_VAINCUS = 'boss_vaincus';
    case RICHESSE = 'richesse';
    case HONNEUR = 'honneur';

    /**
     * Les guildes, classées sur l'XP cumulée de leurs MEMBRES.
     *
     * La seule catégorie dont la ligne classée n'est pas un joueur — d'où `cible()`, que le
     * front lit pour savoir quoi mettre dans les colonnes. C'est précisément pour pouvoir
     * l'ajouter sans toucher à `TypeCumul` que cet enum lui est séparé : une guilde n'a
     * aucune ligne dans `joueur_cumul`.
     */
    case GUILDES = 'guildes';

    public function label(): string
    {
        return match ($this) {
            self::XP_TOTALE => 'Expérience',
            self::MONSTRES_TUES => 'Monstres vaincus',
            self::BOSS_VAINCUS => 'Boss vaincus',
            self::RICHESSE => 'Richesse',
            self::HONNEUR => 'Honneur PvP',
            self::GUILDES => 'Guildes',
        };
    }

    /** Ce que la colonne de valeurs annonce, en tête de tableau. */
    public function intitule(): string
    {
        return match ($this) {
            self::XP_TOTALE => 'XP totale',
            self::MONSTRES_TUES => 'Vaincus',
            self::BOSS_VAINCUS => 'Vaincus',
            self::RICHESSE => 'Or',
            self::HONNEUR => 'Honneur',
            self::GUILDES => 'XP cumulée',
        };
    }

    public function format(): string
    {
        return match ($this) {
            self::RICHESSE => 'or',
            default => 'entier',
        };
    }

    /**
     * Le cumul qui porte la valeur, ou null si c'est un état courant du joueur.
     *
     * C'est cette méthode qui matérialise la distinction posée par `TypeCumul` : un cumul ne
     * redescend jamais, un état est la photo de l'instant. Le classement les affiche pareil,
     * mais ne les lit pas au même endroit — et surtout, `user.money` n'est PAS recopié dans
     * `joueur_cumul`, ce qui créerait une seconde vérité sur l'or.
     */
    public function cumul(): ?TypeCumul
    {
        return match ($this) {
            self::XP_TOTALE, self::GUILDES => TypeCumul::XP_TOTALE,
            self::MONSTRES_TUES => TypeCumul::MONSTRES_TUES,
            self::BOSS_VAINCUS => TypeCumul::BOSS_VAINCUS,
            self::RICHESSE, self::HONNEUR => null,
        };
    }

    /**
     * Ce qui est classé : un joueur, ou une guilde.
     *
     * Le front s'en sert pour ses en-têtes de colonnes — « Classe / Niveau » n'a aucun sens
     * pour une guilde, où l'on veut voir le nombre de membres.
     */
    public function cible(): string
    {
        return $this === self::GUILDES ? 'guilde' : 'joueur';
    }

    /**
     * La colonne de `user` à lire quand ce n'est pas un cumul.
     *
     * Le nom est fourni par l'enum et jamais par une requête : il finit interpolé dans un
     * `ORDER BY`, ce qui n'est sûr que parce que l'ensemble des valeurs possibles est clos ici.
     */
    public function colonneUser(): ?string
    {
        return match ($this) {
            self::RICHESSE => 'money',
            self::HONNEUR => 'honneur',
            default => null,
        };
    }
}
