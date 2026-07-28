<?php

namespace App\Config;

use App\Config\RecolteConfig;
use App\Enum\PorteeRecharge;
use App\Enum\QuestEffect;
use App\Enum\TypeConditionInteraction;
use App\Enum\TypeInteraction;

/**
 * Configuration de l'InteractionMaker : types, portées de recharge et conditions, avec
 * leurs champs. Même patron que QuestActionTypeConfig et DonjonMecaniqueConfig — le front
 * ne connaît aucun type en dur, il rend le formulaire à partir de ce qu'on lui envoie.
 *
 * Ajouter un type d'interaction ou de condition = un case dans l'enum + un case ici.
 */
final class InteractionConfig
{
    public static function all(): array
    {
        return [
            'types' => self::types(),
            'portees' => self::portees(),
            'conditions' => self::conditions(),
            'effets' => self::effets(),
            // Les curseurs de la récolte éthique/intensive : l'éditeur les AFFICHE pour que
            // l'auteur sache ce qu'il arme en cochant « propose le choix », mais ils ne se
            // règlent pas par case (RecolteConfig).
            'modesRecolte' => RecolteConfig::modes(),
        ];
    }

    private static function types(): array
    {
        return array_map(fn (TypeInteraction $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'aide' => match ($type) {
                TypeInteraction::RECOLTER => "Ressource à ramasser. C'est le type à utiliser avec un métier.",
                TypeInteraction::OUVRIR => "Coffre, cache, réserve.",
                TypeInteraction::ACTIONNER => "Levier, mécanisme, stèle.",
                TypeInteraction::EFFET => "Point d'entrée d'un effet scripté du jeu (butin de boss, auberge…).",
            },
        ], TypeInteraction::cases());
    }

    private static function portees(): array
    {
        return array_map(fn (PorteeRecharge $portee) => [
            'value' => $portee->value,
            'label' => $portee->label(),
            'aide' => match ($portee) {
                PorteeRecharge::JOUEUR => "Chaque joueur a son propre délai : tout le monde peut récolter.",
                PorteeRecharge::MONDE => "Un seul délai partagé : le premier arrivé vide la case pour les autres.",
                PorteeRecharge::INSTANCE => "Un délai par expédition de donjon : chaque groupe a son propre état.",
            },
        ], PorteeRecharge::cases());
    }

    private static function conditions(): array
    {
        $config = [];
        foreach (TypeConditionInteraction::cases() as $type) {
            $config[$type->value] = [
                'label' => $type->label(),
                'defauts' => $type->parametres(),
                'champs' => self::champsCondition($type),
            ];
        }

        return $config;
    }

    private static function champsCondition(TypeConditionInteraction $type): array
    {
        return match ($type) {
            TypeConditionInteraction::NIVEAU => [
                ['name' => 'niveau', 'type' => 'number', 'label' => 'Niveau minimum'],
            ],
            TypeConditionInteraction::CLASSE => [
                ['name' => 'classeId', 'type' => 'select', 'label' => 'Classe', 'catalog' => 'classes'],
            ],
            TypeConditionInteraction::ALIGNEMENT => [
                ['name' => 'alignementId', 'type' => 'select', 'label' => 'Alignement', 'catalog' => 'alignements'],
            ],
            TypeConditionInteraction::QUETE_TERMINEE => [
                ['name' => 'queteId', 'type' => 'select', 'label' => 'Quête', 'catalog' => 'quetes'],
            ],
            TypeConditionInteraction::POSSEDE_OBJET => [
                ['name' => 'objetId', 'type' => 'select', 'label' => 'Objet', 'catalog' => 'objets'],
                ['name' => 'quantite', 'type' => 'number', 'label' => 'Quantité'],
            ],
        };
    }

    /** Effets scriptés utilisables depuis une case (whitelist serveur existante). */
    private static function effets(): array
    {
        return array_map(fn (QuestEffect $effet) => [
            'value' => $effet->value,
            'label' => $effet->label(),
        ], QuestEffect::cases());
    }
}
