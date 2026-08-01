<?php

namespace App\Config;

/**
 * Curseurs des guildes, en UN seul endroit — pendant de `JournalConfig` et `ClassementConfig`.
 * Aucun chiffre en dur côté client : ils descendent avec l'annuaire.
 */
final class GuildeConfig
{
    /**
     * Ce que coûte la fondation d'une guilde.
     *
     * Un coût, et pas zéro : sans lui, rien n'empêche de créer une guilde par lubie, et
     * l'annuaire se remplit de coquilles vides à un membre. C'est aussi un puits monétaire
     * de plus, mesurable au tableau de bord.
     */
    public const COUT_CREATION = 5000;

    /** Places d'une guilde neuve. Le champ reste éditable en base. */
    public const PLACE_MAX_DEFAUT = 20;

    public const NOM_MIN = 3;
    public const NOM_MAX = 40;
    public const DESCRIPTION_MAX = 500;

    /**
     * Niveau de personnage requis pour fonder.
     *
     * Fonder est un acte structurant ; un compte créé il y a trente secondes ne devrait pas
     * pouvoir peupler l'annuaire. Candidater, en revanche, ne demande rien.
     */
    public const NIVEAU_MIN_CREATION = 5;

    /** Ce que le front doit connaître pour afficher ses formulaires et ses messages. */
    public static function pourLeFront(): array
    {
        return [
            'coutCreation' => self::COUT_CREATION,
            'placeMaxDefaut' => self::PLACE_MAX_DEFAUT,
            'nomMin' => self::NOM_MIN,
            'nomMax' => self::NOM_MAX,
            'descriptionMax' => self::DESCRIPTION_MAX,
            'niveauMinCreation' => self::NIVEAU_MIN_CREATION,
        ];
    }
}
