<?php

namespace App\Enum;

/**
 * Un total de partie, SANS cible. Les valeurs sont stockées en base (`joueur_cumul.cle`) :
 * ne JAMAIS les renommer — même contrat que `TypeCompteur` et `TypeEvenement`.
 *
 * ## Pourquoi une table à part et pas un `TypeCompteur`
 *
 * `joueur_compteur` répond à « combien de fois, PAR CIBLE » (tel monstre, telle recette) ;
 * `joueur_cumul` répond à « combien au TOTAL ». Ce ne sont pas deux usages du même modèle :
 * `CompteurJoueurService::incrementer` **refuse `$cibleId <= 0`**, il n'existe donc pas de
 * « cible 0 » où loger un total sans cible. Inventer une fausse cible casserait l'invariant
 * que `CLAUDE.md` décrit comme la clé de voûte des compteurs.
 *
 * C'est aussi la réponse à la question laissée ouverte en doc §18 à propos de `KILL_PVP`
 * (« la cible n'est pas un id de contenu mais une classe ou un alignement — à arbitrer ») :
 * il n'y a PAS de cible, donc il n'y a pas de compteur à cible. Le détail « qui, quand,
 * combien de victimes distinctes » vit dans le journal, où il a sa place.
 *
 * ## Ce qui n'est PAS un cumul
 *
 * La richesse (`user.money`) et l'honneur (`user.honneur`) sont des **états courants**, pas
 * des totaux. Leurs classements les lisent directement. Les recopier ici créerait une
 * seconde vérité sur l'or, ce que `CLAUDE.md` interdit frontalement.
 */
enum TypeCumul: string
{
    /**
     * Expérience gagnée depuis la création du personnage.
     *
     * Elle n'est PAS déductible de `niveau_joueur.experience`, qui est l'XP *courante dans le
     * niveau* : `LevelingService` la décrémente à chaque palier franchi et la mort en retire
     * 9 %. D'où ce cumul, alimenté au seul point de passage de l'XP.
     */
    case XP_TOTALE = 'xp_totale';

    /** Dénormalisation de `SUM(joueur_compteur.valeur)` pour `monstre_tue`. Recalculable. */
    case MONSTRES_TUES = 'monstres_tues';

    /**
     * Dénormalisation de `SUM(user_boss.number_kill)`.
     *
     * On ne crée surtout pas de `TypeCompteur::BOSS_VAINCU` : `user_boss` reste la source
     * (`ActionType::BATTRE_BOSS` en dépend, et `CLAUDE.md` dit que changer sa sémantique
     * casserait le contenu). Ce qui rend la dénormalisation légitime, c'est qu'elle est
     * **recalculable** — `app:cumuls:reparer` la refait depuis sa source.
     */
    case BOSS_VAINCUS = 'boss_vaincus';

    /** Joueurs tués en PvP. Alimenté au lot PvP : `diePlayer` ne connaît pas encore le tueur. */
    case JOUEURS_TUES = 'joueurs_tues';

    /** Morts du joueur, toutes causes confondues. */
    case MORTS = 'morts';

    /** Or REÇU (butin de vente, échange, quêtes). */
    case OR_GAGNE = 'or_gagne';

    /** Or DÉPENSÉ (achats, frais de dépôt, coûts de quête). */
    case OR_DEPENSE = 'or_depense';

    public function label(): string
    {
        return match ($this) {
            self::XP_TOTALE => 'Expérience totale gagnée',
            self::MONSTRES_TUES => 'Monstres vaincus',
            self::BOSS_VAINCUS => 'Boss vaincus',
            self::JOUEURS_TUES => 'Joueurs vaincus',
            self::MORTS => 'Morts',
            self::OR_GAGNE => 'Or gagné',
            self::OR_DEPENSE => 'Or dépensé',
        };
    }

    /** Unité au singulier, pour les phrases de progression. */
    public function unite(): string
    {
        return match ($this) {
            self::XP_TOTALE => "point(s) d'expérience",
            self::MONSTRES_TUES, self::BOSS_VAINCUS, self::JOUEURS_TUES => 'vaincu(s)',
            self::MORTS => 'fois',
            self::OR_GAGNE, self::OR_DEPENSE => "pièce(s) d'or",
        };
    }

    /**
     * Comment le front doit rendre la valeur. Il ne connaît aucune clé en dur : c'est le
     * serveur qui dit « celui-ci s'affiche avec l'icône d'or », même discipline que
     * `TypeCompteur::unite()` et `FamilleMetier::label()`.
     */
    public function format(): string
    {
        return match ($this) {
            self::OR_GAGNE, self::OR_DEPENSE => 'or',
            default => 'entier',
        };
    }

    /**
     * Les cumuls montrés sur la fiche du joueur, dans l'ordre d'affichage.
     *
     * `OR_GAGNE`/`OR_DEPENSE` en sont volontairement absents : la fiche montre déjà la
     * richesse COURANTE, et afficher les trois côte à côte inviterait à les additionner
     * alors qu'ils ne se composent pas (l'or gagné puis dépensé n'est plus dans la bourse).
     * Ils restent lisibles dans le tableau de bord d'administration.
     *
     * @return list<self>
     */
    public static function faitsDArmes(): array
    {
        return [
            self::XP_TOTALE,
            self::MONSTRES_TUES,
            self::BOSS_VAINCUS,
            self::JOUEURS_TUES,
            self::MORTS,
        ];
    }
}
