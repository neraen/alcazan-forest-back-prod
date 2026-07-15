<?php

namespace App\Config;

use App\Enum\ActionType;
use App\Enum\QuestEffect;

/**
 * Configuration des types d'action pour le QuestMaker (remplace les tables
 * action_field / action_field_type). Le front rend les champs d'une action
 * uniquement à partir de cette config, exposée par /api/quest/editor/config.
 *
 * field.type : "number" | "select" | "json"
 * field.catalog : clé du référentiel (/api/quest/editor/referentiels) pour les selects.
 */
final class QuestActionTypeConfig
{
    public static function all(): array
    {
        $config = [];
        foreach (ActionType::cases() as $type) {
            if (!$type->isImplemented()) {
                continue;
            }
            $config[$type->name] = self::forType($type);
        }

        return $config;
    }

    private static function forType(ActionType $type): array
    {
        return match ($type) {
            ActionType::SCRIPTED_EFFECT => [
                'label' => 'Effet scripté',
                'isCondition' => false,
                'fields' => [
                    ['name' => 'effect', 'type' => 'select', 'label' => 'Effet', 'options' => self::effectOptions()],
                    ['name' => 'effectParams', 'type' => 'json', 'label' => 'Paramètres (JSON)'],
                ],
            ],
            ActionType::DONNER_OBJET => [
                'label' => 'Donner un objet',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'objetId', 'type' => 'select', 'label' => 'Objet', 'catalog' => 'objets'],
                    ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantité'],
                ],
            ],
            ActionType::DONNER_OR => [
                'label' => "Donner de l'or",
                'isCondition' => true,
                'fields' => [
                    ['name' => 'quantity', 'type' => 'number', 'label' => "Quantité d'or"],
                ],
            ],
            ActionType::DONNER_EQUIPEMENT => [
                'label' => 'Donner un équipement',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'equipementId', 'type' => 'select', 'label' => 'Équipement', 'catalog' => 'equipements'],
                    ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantité'],
                ],
            ],
            ActionType::DONNER_CONSOMMABLE => [
                'label' => 'Donner un consommable',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'consommableId', 'type' => 'select', 'label' => 'Consommable', 'catalog' => 'consommables'],
                    ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantité'],
                ],
            ],
            ActionType::ATTEINDRE_LEVEL => [
                'label' => 'Atteindre un niveau',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'quantity', 'type' => 'number', 'label' => 'Niveau requis'],
                ],
            ],
            ActionType::PARLER_PNJ => [
                'label' => 'Parler à un PNJ',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'pnjId', 'type' => 'select', 'label' => 'PNJ', 'catalog' => 'pnjs'],
                ],
            ],
            ActionType::BATTRE_BOSS => [
                'label' => 'Battre un boss',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'bossId', 'type' => 'select', 'label' => 'Boss', 'catalog' => 'bosses'],
                    ['name' => 'quantity', 'type' => 'number', 'label' => 'Nombre de kills'],
                ],
            ],
            ActionType::PASSER_DIALOGUE => [
                'label' => 'Passer le dialogue',
                'isCondition' => false,
                'fields' => [],
            ],
            ActionType::POSSEDER_OBJET => [
                'label' => 'Posséder un objet (sans le consommer)',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'objetId', 'type' => 'select', 'label' => 'Objet', 'catalog' => 'objets'],
                    ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantité'],
                ],
            ],
            ActionType::VISITER_CARTE => [
                'label' => 'Visiter une carte',
                'isCondition' => true,
                'fields' => [
                    ['name' => 'carteId', 'type' => 'select', 'label' => 'Carte', 'catalog' => 'cartes'],
                ],
            ],
        };
    }

    private static function effectOptions(): array
    {
        $options = [];
        foreach (QuestEffect::cases() as $effect) {
            $options[] = ['value' => $effect->value, 'label' => $effect->label()];
        }

        return $options;
    }
}
