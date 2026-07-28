<?php

namespace App\Config;

use App\Enum\ModeCraft;

/**
 * Les curseurs d'équilibrage de la fabrication, en UN seul endroit — pendant de
 * `RecolteConfig`. Ce qui est du contenu (temps de base, niveau requis, ingrédients,
 * sortie, XP) reste porté par la recette : ici, on ne décrit que ce que chaque manière
 * de fabriquer FAIT à ces valeurs.
 */
final class CraftConfig
{
    /**
     * Combien de fabrications un joueur peut mener de front.
     *
     * Un plafond, parce que la résolution est paresseuse : sans lui, rien n'empêcherait
     * d'empiler mille commandes d'un coup et de revenir tout ramasser plus tard, ce qui
     * annulerait le temps de production comme contrainte.
     */
    public const COMMANDES_SIMULTANEES_MAX = 3;

    /** Multiplicateur du temps de production de la recette. */
    public static function multiplicateurTemps(ModeCraft $mode): float
    {
        return match ($mode) {
            ModeCraft::RECYCLAGE => 1.0,
            ModeCraft::RAPIDE => 0.25,
        };
    }

    /**
     * Part des ingrédients rendue au retrait, en pourcentage. Appliquée à l'instantané
     * pris au LANCEMENT, jamais à la recette : celle-ci a pu être éditée entre-temps.
     */
    public static function pourcentageRecycle(ModeCraft $mode): int
    {
        return match ($mode) {
            ModeCraft::RECYCLAGE => 30,
            ModeCraft::RAPIDE => 0,
        };
    }

    /** Ajustement de karma au retrait. Voir KarmaService : aucun effet de jeu à ce stade. */
    public static function karma(ModeCraft $mode): int
    {
        return match ($mode) {
            ModeCraft::RECYCLAGE => 1,
            ModeCraft::RAPIDE => -1,
        };
    }

    /**
     * Ce que le front affiche pour laisser le joueur choisir — descendu par le serveur,
     * comme les modes de récolte : aucun chiffre en dur côté client.
     */
    public static function modes(): array
    {
        return array_map(fn (ModeCraft $mode) => [
            'value' => $mode->value,
            'label' => $mode->label(),
            'description' => $mode->description(),
            'temps' => self::multiplicateurTemps($mode),
            'recycle' => self::pourcentageRecycle($mode),
            'karma' => self::karma($mode),
        ], ModeCraft::cases());
    }
}
