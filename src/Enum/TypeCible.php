<?php

namespace App\Enum;

/**
 * Ce que désigne `evenement_jeu.cible_id`, qui est un ENTIER NU sans clé étrangère.
 *
 * Même raisonnement que `TypeCompteur` pour `joueur_compteur.cible_id` : la colonne est
 * polymorphe, et trois FK nullables mutuellement exclusives coûteraient plus qu'elles ne
 * garantissent. Contrepartie assumée : un contenu supprimé laisse un événement dont la
 * cible ne se résout plus — `JournalNormalizer` affiche alors « Monstre inconnu (#12) ».
 * C'est acceptable pour une table d'archive ; ça ne le serait pas pour du gameplay.
 *
 * Les trois premières valeurs sont CELLES de `TypeItem` : un événement qui porte un item
 * doit pouvoir aller et venir entre les deux enums sans table de correspondance.
 */
enum TypeCible: string
{
    case EQUIPEMENT = 'equipement';
    case CONSOMMABLE = 'consommable';
    case OBJET = 'objet';
    case MONSTRE = 'monstre';
    case BOSS = 'boss';
    case RECETTE = 'recette';
    case QUETE = 'quete';
    case GUILDE = 'guilde';
    case PNJ = 'pnj';
    case INTERACTION = 'interaction';

    /** Le libellé au singulier, employé tel quel dans « Monstre inconnu (#12) ». */
    public function label(): string
    {
        return match ($this) {
            self::EQUIPEMENT => 'Équipement',
            self::CONSOMMABLE => 'Consommable',
            self::OBJET => 'Objet',
            self::MONSTRE => 'Monstre',
            self::BOSS => 'Boss',
            self::RECETTE => 'Recette',
            self::QUETE => 'Quête',
            self::GUILDE => 'Guilde',
            self::PNJ => 'PNJ',
            self::INTERACTION => 'Interaction',
        };
    }

    /** La famille d'item correspondante, ou null si la cible n'est pas un item. */
    public function typeItem(): ?TypeItem
    {
        return TypeItem::tryFrom($this->value);
    }

    public static function depuisItem(TypeItem $type): self
    {
        return self::from($type->value);
    }
}
