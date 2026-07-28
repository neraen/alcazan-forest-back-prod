<?php

namespace App\Enum;

/**
 * Conditions d'accès à une interaction, au-delà du métier (qui est porté directement par
 * l'interaction, cf. Interaction::$metier). Les valeurs sont stockées en base.
 *
 * Whitelist serveur : le client ne décide jamais si une condition est remplie.
 */
enum TypeConditionInteraction: string
{
    /** params: {niveau: int} */
    case NIVEAU = 'niveau';

    /** params: {classeId: int} */
    case CLASSE = 'classe';

    /** params: {queteId: int} */
    case QUETE_TERMINEE = 'quete_terminee';

    /** params: {objetId: int, quantite: int} — l'objet n'est PAS consommé. */
    case POSSEDE_OBJET = 'possede_objet';

    /** params: {alignementId: int} */
    case ALIGNEMENT = 'alignement';

    public function label(): string
    {
        return match ($this) {
            self::NIVEAU => 'Niveau minimum',
            self::CLASSE => 'Classe requise',
            self::QUETE_TERMINEE => 'Quête terminée',
            self::POSSEDE_OBJET => 'Possède un objet',
            self::ALIGNEMENT => 'Alignement requis',
        };
    }

    public function parametres(): array
    {
        return match ($this) {
            self::NIVEAU => ['niveau' => 1],
            self::CLASSE => ['classeId' => null],
            self::QUETE_TERMINEE => ['queteId' => null],
            self::POSSEDE_OBJET => ['objetId' => null, 'quantite' => 1],
            self::ALIGNEMENT => ['alignementId' => null],
        };
    }
}
