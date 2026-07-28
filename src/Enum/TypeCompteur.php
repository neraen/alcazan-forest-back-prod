<?php

namespace App\Enum;

/**
 * Ce qu'un compteur de progression compte. Les valeurs sont stockées en base
 * (`joueur_compteur.type`) : ne JAMAIS les renommer.
 *
 * Un compteur est TOUJOURS une paire (type, cible) : le type dit ce qu'on compte et,
 * par voie de conséquence, ce qu'est la cible. C'est ce qui permet à `RESSOURCE_RECOLTEE`
 * et `OBJET_FABRIQUE` de viser tous deux un `objet` sans jamais se confondre — un objet
 * ramassé sur un buisson n'est pas un objet sorti d'un atelier, et une quête d'artisan
 * ne doit pas se valider en récoltant.
 *
 * Ajouter un compteur = un case ici + un case dans les trois `match` ci-dessous + le
 * branchement dans le service qui produit l'événement. Rien d'autre : le QuestMaker se
 * configure depuis `QuestActionTypeConfig`, qui lit ces libellés.
 */
enum TypeCompteur: string
{
    /** Cible = `monstre.id`. Incrémenté à la mort d'un monstre (DeathService). */
    case MONSTRE_TUE = 'monstre_tue';

    /**
     * Cible = `recette.id`. Incrémenté au RETRAIT d'une fabrication (CraftService),
     * pas à son lancement : ce qui compte est ce qui sort de l'atelier, sans quoi
     * lancer puis annuler ferait progresser une quête d'artisan pour rien.
     */
    case OBJET_FABRIQUE = 'objet_fabrique';

    /**
     * Cible = `objet.id`. Incrémenté de la quantité RÉELLEMENT obtenue sur une case
     * interactive de type « récolter » (InteractionService) — la récolte intensive
     * compte donc triple, comme elle rapporte triple.
     */
    case RESSOURCE_RECOLTEE = 'ressource_recoltee';

    public function label(): string
    {
        return match ($this) {
            self::MONSTRE_TUE => 'Monstres vaincus',
            self::OBJET_FABRIQUE => 'Fabrications terminées',
            self::RESSOURCE_RECOLTEE => 'Ressources récoltées',
        };
    }

    /**
     * Phrase de progression affichée au joueur, au singulier de l'unité comptée.
     * Elle vit ici plutôt que dans le front pour la même raison que les libellés de
     * famille de métier : le client ne doit connaître aucun cas de l'enum en dur.
     */
    public function unite(): string
    {
        return match ($this) {
            self::MONSTRE_TUE => 'vaincu(s)',
            self::OBJET_FABRIQUE => 'fabriqué(s)',
            self::RESSOURCE_RECOLTEE => 'récolté(s)',
        };
    }

    /** Clé d'un compteur dans l'instantané de départ d'une étape de quête. */
    public function cle(int $cibleId): string
    {
        return $this->value . ':' . $cibleId;
    }
}
