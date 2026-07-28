<?php

namespace App\Config;

use App\Enum\ConditionSalleDonjon;
use App\Enum\MecaniqueDonjon;
use App\Enum\TypeSalleDonjon;

/**
 * Configuration du DonjonMaker : quels champs afficher pour quel type de mécanique.
 * Même patron que QuestActionTypeConfig — le front ne connaît aucun type en dur, il
 * rend le formulaire à partir de ce que renvoie /api/donjon/editor/config.
 *
 * field.type : "number" | "text"
 *
 * Ajouter une mécanique = un case dans MecaniqueDonjon + un case ici. Le front suit.
 */
final class DonjonMecaniqueConfig
{
    public static function all(): array
    {
        $config = [];
        foreach (MecaniqueDonjon::cases() as $type) {
            $config[$type->value] = self::forType($type);
        }

        return $config;
    }

    /** Types de salle, pour le select du plan du donjon. */
    public static function typesDeSalle(): array
    {
        return array_map(
            fn (TypeSalleDonjon $type) => ['value' => $type->value, 'label' => $type->label()],
            TypeSalleDonjon::cases()
        );
    }

    /**
     * Conditions d'entrée d'une salle, avec leurs champs — mêmes conventions que les
     * mécaniques : le front rend le formulaire sans connaître aucune condition en dur.
     */
    public static function conditionsDeSalle(): array
    {
        $config = [];
        foreach (ConditionSalleDonjon::cases() as $condition) {
            $config[$condition->value] = [
                'label' => $condition->label(),
                'aide' => self::aideCondition($condition),
                'defauts' => $condition->parametres(),
                'champs' => self::champsCondition($condition),
            ];
        }

        return $config;
    }

    private static function aideCondition(ConditionSalleDonjon $condition): string
    {
        return match ($condition) {
            ConditionSalleDonjon::AUCUNE =>
                "Le groupe traverse librement.",
            ConditionSalleDonjon::SALLE_NETTOYEE =>
                "Il faut avoir tué toute la population de la salle PRÉCÉDENTE. "
                . "Pensez à donner une population à cette salle-là.",
            ConditionSalleDonjon::LEVIERS =>
                "Des leviers de la salle précédente doivent être actionnés par des joueurs "
                . "DIFFÉRENTS dans la même fenêtre de temps. Poser les leviers avec le "
                . "MapMaker (case action, effet « Actionner un levier »).",
            ConditionSalleDonjon::BOSS_VAINCU =>
                "Réservé à la salle au trésor : le gardien doit être tombé.",
        };
    }

    private static function champsCondition(ConditionSalleDonjon $condition): array
    {
        return match ($condition) {
            ConditionSalleDonjon::LEVIERS => [
                ['name' => 'leviers', 'type' => 'number', 'label' => 'Leviers à actionner'],
                ['name' => 'fenetreSecondes', 'type' => 'number', 'label' => 'Fenêtre de temps (s)',
                 'aide' => "C'est la simultanéité qui force la coordination"],
            ],
            default => [],
        };
    }

    private static function forType(MecaniqueDonjon $type): array
    {
        return [
            'label' => $type->label(),
            'aide' => self::aide($type),
            'defauts' => $type->parametres(),
            'champs' => self::champs($type),
        ];
    }

    /** Une phrase qui dit à quoi sert la mécanique EN JEU, pas ce qu'elle fait techniquement. */
    private static function aide(MecaniqueDonjon $type): string
    {
        return match ($type) {
            MecaniqueDonjon::ZONE_TELEGRAPHIEE =>
                "Le boss annonce des cases, qui frappent après le délai. C'est ce qui rend "
                . "le déplacement décisif : sans zone, la position n'a aucune importance.",
            MecaniqueDonjon::ADDS =>
                "Des renforts apparaissent autour du boss et doivent être gérés en parallèle. "
                . "Oblige le groupe à se répartir les cibles.",
            MecaniqueDonjon::ENRAGE =>
                "Passé le délai, les dégâts du boss sont multipliés. Impose un minimum de "
                . "dégâts au groupe et donne un rythme à la rencontre.",
            MecaniqueDonjon::ENIGME_LEVIERS =>
                "Des leviers doivent être actionnés par des joueurs DIFFÉRENTS dans la même "
                . "fenêtre de temps. Poser les leviers sur la carte avec le MapMaker "
                . "(case action, effet « Actionner un levier »).",
        };
    }

    private static function champs(MecaniqueDonjon $type): array
    {
        return match ($type) {
            MecaniqueDonjon::ZONE_TELEGRAPHIEE => [
                ['name' => 'rayon', 'type' => 'number', 'label' => 'Rayon (en cases)',
                 'aide' => '0 = une seule case, 1 = un carré de 3×3'],
                ['name' => 'degats', 'type' => 'number', 'label' => 'Dégâts'],
                ['name' => 'delaiSecondes', 'type' => 'number', 'label' => 'Délai avant impact (s)',
                 'aide' => 'Le temps laissé aux joueurs pour sortir de la zone'],
            ],
            MecaniqueDonjon::ADDS => [
                ['name' => 'monstreId', 'type' => 'select', 'label' => 'Monstre',
                 'catalog' => 'monstres', 'aide' => 'Monstre du MonsterMaker à faire apparaître'],
                ['name' => 'quantite', 'type' => 'number', 'label' => 'Nombre de renforts'],
            ],
            MecaniqueDonjon::ENRAGE => [
                ['name' => 'apresSecondes', 'type' => 'number', 'label' => 'Déclenchement après (s)'],
                ['name' => 'multiplicateur', 'type' => 'number', 'label' => 'Multiplicateur de dégâts'],
            ],
            MecaniqueDonjon::ENIGME_LEVIERS => [
                ['name' => 'leviers', 'type' => 'number', 'label' => 'Leviers à actionner'],
                ['name' => 'fenetreSecondes', 'type' => 'number', 'label' => 'Fenêtre de temps (s)'],
                ['name' => 'degatsBoss', 'type' => 'number', 'label' => 'Dégâts infligés au boss'],
            ],
        };
    }
}
