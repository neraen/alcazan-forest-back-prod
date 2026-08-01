<?php

namespace App\Enum;

/**
 * Le rang d'un joueur dans sa guilde. Les valeurs sont stockées en base
 * (`joueur_guilde.grade`) : ne JAMAIS les renommer.
 *
 * ## Pourquoi un enum PHP et pas la table `Grade`
 *
 * La table existait, vide de sémantique (un nom, une icône) et **vide de données** — zéro
 * ligne. Elle ne portait aucune permission, et des permissions en base sont de toute façon
 * interprétées par le code : on aurait eu à maintenir les deux. Même raisonnement que pour
 * `ActionType`, que `CLAUDE.md` garde délibérément en dur. `Grade` et `JoueurGrade` sont donc
 * supprimées avec ce lot.
 *
 * ## Les permissions sont ici, et nulle part ailleurs
 *
 * `GuildeService` les interroge et ne les redéfinit jamais. Les disperser dans le service
 * serait le meilleur moyen d'avoir un jour « un officier peut accepter » à un endroit et
 * « seul le baron accepte » à un autre.
 */
enum GradeGuilde: string
{
    case BARON = 'baron';
    case OFFICIER = 'officier';
    case MEMBRE = 'membre';
    case RECRUE = 'recrue';

    public function label(): string
    {
        return match ($this) {
            self::BARON => 'Baron',
            self::OFFICIER => 'Officier',
            self::MEMBRE => 'Membre',
            self::RECRUE => 'Recrue',
        };
    }

    /** Plus le rang est haut, plus le grade est élevé. Sert à comparer, jamais à afficher. */
    public function rang(): int
    {
        return match ($this) {
            self::BARON => 3,
            self::OFFICIER => 2,
            self::MEMBRE => 1,
            self::RECRUE => 0,
        };
    }

    /** Accepter ou refuser une candidature : officier et au-dessus. */
    public function peutAccepter(): bool
    {
        return $this->rang() >= self::OFFICIER->rang();
    }

    /**
     * Changer le grade d'un membre : le baron seul.
     *
     * Un officier qui pourrait promouvoir officier ferait proliférer les officiers sans que
     * le baron ait son mot à dire — et comme un officier peut exclure une recrue, la guilde
     * lui échapperait.
     */
    public function peutPromouvoir(): bool
    {
        return $this === self::BARON;
    }

    /**
     * Exclure quelqu'un demande un grade STRICTEMENT supérieur au sien.
     *
     * « Supérieur ou égal » laisserait deux officiers s'exclure mutuellement, et le premier à
     * cliquer gagnerait — ce n'est pas une règle, c'est une course.
     */
    public function peutExclure(self $cible): bool
    {
        return $this->peutAccepter() && $this->rang() > $cible->rang();
    }

    public function peutDissoudre(): bool
    {
        return $this === self::BARON;
    }

    /** Les grades qu'un baron peut attribuer : tous sauf le sien. */
    public static function attribuables(): array
    {
        return [self::OFFICIER, self::MEMBRE, self::RECRUE];
    }
}
