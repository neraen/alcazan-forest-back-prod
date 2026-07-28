<?php

namespace App\Config;

use App\Enum\ModeRecolte;

/**
 * Les curseurs d'équilibrage de la récolte, en UN seul endroit. Ce qui est du contenu
 * (butin, cooldown de base, XP de métier) reste porté par l'interaction elle-même : ici,
 * on ne décrit que ce que chaque manière de prélever FAIT à ces valeurs.
 *
 * Trois leviers, et ils ne disent pas la même chose :
 *
 *  - `quantite` multiplie le butin — c'est le gain IMMÉDIAT, ce qui rend l'intensif tentant ;
 *  - `cooldown` multiplie le délai PERSONNEL — ce que le joueur se coûte à lui-même ;
 *  - `epuisement` pose un délai PARTAGÉ sur la case — ce qu'il coûte aux autres.
 *
 * Le troisième est le seul qui sorte du cadre d'un joueur seul, et c'est tout l'intérêt
 * du lot : sans lui, la portée JOUEUR donne à chacun son propre cooldown et une récolte
 * intensive ne peut, par construction, léser personne.
 */
final class RecolteConfig
{
    /** Multiplicateur du butin (quantités d'items et or, jamais l'XP de personnage). */
    public static function multiplicateurQuantite(ModeRecolte $mode): int
    {
        return match ($mode) {
            ModeRecolte::ETHIQUE => 1,
            ModeRecolte::INTENSIVE => 3,
        };
    }

    /**
     * Multiplicateur du cooldown personnel. L'éthique se recharge DEUX FOIS plus vite que
     * le réglage de la case : c'est la contrepartie du rendement moindre.
     */
    public static function multiplicateurCooldown(ModeRecolte $mode): float
    {
        return match ($mode) {
            ModeRecolte::ETHIQUE => 0.5,
            ModeRecolte::INTENSIVE => 2.0,
        };
    }

    /**
     * Durée pendant laquelle le gisement est mort POUR TOUT LE MONDE, en multiple du
     * cooldown de la case. Zéro = aucun épuisement partagé.
     */
    public static function multiplicateurEpuisement(ModeRecolte $mode): float
    {
        return match ($mode) {
            ModeRecolte::ETHIQUE => 0.0,
            ModeRecolte::INTENSIVE => 3.0,
        };
    }

    /** Ajustement de karma. Voir KarmaService : aucun effet de jeu à ce stade. */
    public static function karma(ModeRecolte $mode): int
    {
        return match ($mode) {
            ModeRecolte::ETHIQUE => 1,
            ModeRecolte::INTENSIVE => -2,
        };
    }

    /**
     * Ce que le front affiche pour laisser le joueur choisir. Descendu par le serveur,
     * comme les types d'interaction : le front n'écrit aucun chiffre en dur, sans quoi
     * retoucher l'équilibrage mentirait à l'écran.
     */
    public static function modes(): array
    {
        return array_map(fn (ModeRecolte $mode) => [
            'value' => $mode->value,
            'label' => $mode->label(),
            'description' => $mode->description(),
            'quantite' => self::multiplicateurQuantite($mode),
            'cooldown' => self::multiplicateurCooldown($mode),
            'epuisement' => self::multiplicateurEpuisement($mode),
            'karma' => self::karma($mode),
        ], ModeRecolte::cases());
    }
}
