<?php

namespace App\Enum;

/**
 * Ce qu'un événement de jeu raconte. Les valeurs sont stockées en base
 * (`evenement_jeu.type`) : ne JAMAIS les renommer — même contrat que `TypeCompteur`.
 *
 * Un type = UN FAIT, pas une mutation. C'est l'invariant central du journal, et il
 * dicte deux choix qui se lisent mal si on ne les explicite pas :
 *
 *  - **Pas de `OR_GAGNE`/`OR_PERDU` ni de `ITEM_OBTENU`/`ITEM_PERDU` génériques.** Un
 *    achat à l'hôtel des ventes produirait alors quatre lignes qu'aucune colonne ne
 *    relie entre elles, et le journal cesserait de raconter une histoire pour redevenir
 *    un log d'inventaire — que `SacService` garantit déjà par construction. Un achat,
 *    c'est UNE ligne `HDV_ACHAT` : acteur = acheteur, cible_user = vendeur,
 *    `montant_or` = prix, contexte = les items.
 *  - **`MORT_JOUEUR` couvre toutes les morts**, plutôt qu'un couple `JOUEUR_TUE`/`MORT`.
 *    `acteur_id` porte le tueur (NULL si c'est l'environnement), `cible_user_id` le mort.
 *    Grâce aux deux index, la même ligne se lit dans les deux sens : « les joueurs que X
 *    a tués » et « les fois où X est mort ». Dupliquer l'événement doublerait le volume
 *    et créerait deux vérités à réconcilier.
 *
 * Ajouter un type = un case ici + les trois `match` ci-dessous + le branchement dans le
 * service qui produit le fait. Le front n'en connaît aucun en dur : il lit le référentiel
 * servi par `/api/admin/stats/referentiels`.
 */
enum TypeEvenement: string
{
    case MONSTRE_TUE = 'monstre_tue';
    case BOSS_VAINCU = 'boss_vaincu';
    case MORT_JOUEUR = 'mort_joueur';
    case XP_GAGNEE = 'xp_gagnee';
    case NIVEAU_ATTEINT = 'niveau_atteint';
    case ECHANGE_CONCLU = 'echange_conclu';
    case HDV_DEPOT = 'hdv_depot';
    case HDV_ACHAT = 'hdv_achat';
    case HDV_RETRAIT = 'hdv_retrait';
    case HDV_EXPIRATION = 'hdv_expiration';
    case ACHAT_PNJ = 'achat_pnj';
    case VENTE_PNJ = 'vente_pnj';
    case CRAFT_TERMINE = 'craft_termine';
    case RECOLTE = 'recolte';
    case QUETE_TERMINEE = 'quete_terminee';
    case CONNEXION = 'connexion';

    case GUILDE_CREEE = 'guilde_creee';
    case GUILDE_CANDIDATURE = 'guilde_candidature';
    case GUILDE_ACCEPTATION = 'guilde_acceptation';
    case GUILDE_REFUS = 'guilde_refus';
    case GUILDE_DEPART = 'guilde_depart';
    case GUILDE_EXCLUSION = 'guilde_exclusion';
    case GUILDE_GRADE = 'guilde_grade';
    case GUILDE_DISSOUTE = 'guilde_dissoute';

    public function label(): string
    {
        return match ($this) {
            self::MONSTRE_TUE => 'Monstre vaincu',
            self::BOSS_VAINCU => 'Boss vaincu',
            self::MORT_JOUEUR => 'Mort d\'un joueur',
            self::XP_GAGNEE => 'Expérience gagnée',
            self::NIVEAU_ATTEINT => 'Niveau atteint',
            self::ECHANGE_CONCLU => 'Échange conclu',
            self::HDV_DEPOT => 'Mise en vente',
            self::HDV_ACHAT => 'Achat à l\'hôtel des ventes',
            self::HDV_RETRAIT => 'Retrait d\'une annonce',
            self::HDV_EXPIRATION => 'Annonce expirée',
            self::ACHAT_PNJ => 'Achat en échoppe',
            self::VENTE_PNJ => 'Vente en échoppe',
            self::CRAFT_TERMINE => 'Fabrication retirée',
            self::RECOLTE => 'Récolte',
            self::QUETE_TERMINEE => 'Quête terminée',
            self::CONNEXION => 'Connexion',
            self::GUILDE_CREEE => 'Guilde fondée',
            self::GUILDE_CANDIDATURE => 'Candidature à une guilde',
            self::GUILDE_ACCEPTATION => 'Candidature acceptée',
            self::GUILDE_REFUS => 'Candidature refusée',
            self::GUILDE_DEPART => 'Départ d\'une guilde',
            self::GUILDE_EXCLUSION => 'Exclusion d\'une guilde',
            self::GUILDE_GRADE => 'Changement de grade',
            self::GUILDE_DISSOUTE => 'Guilde dissoute',
        };
    }

    public function categorie(): CategorieEvenement
    {
        return match ($this) {
            self::MONSTRE_TUE,
            self::BOSS_VAINCU,
            self::MORT_JOUEUR => CategorieEvenement::COMBAT,

            self::ECHANGE_CONCLU,
            self::HDV_DEPOT,
            self::HDV_ACHAT,
            self::HDV_RETRAIT,
            self::HDV_EXPIRATION,
            self::ACHAT_PNJ,
            self::VENTE_PNJ => CategorieEvenement::ECONOMIE,

            self::XP_GAGNEE,
            self::NIVEAU_ATTEINT,
            self::CRAFT_TERMINE,
            self::RECOLTE,
            self::QUETE_TERMINEE => CategorieEvenement::PROGRESSION,

            self::GUILDE_CREEE,
            self::GUILDE_CANDIDATURE,
            self::GUILDE_ACCEPTATION,
            self::GUILDE_REFUS,
            self::GUILDE_DEPART,
            self::GUILDE_EXCLUSION,
            self::GUILDE_GRADE,
            self::GUILDE_DISSOUTE => CategorieEvenement::SOCIAL,

            self::CONNEXION => CategorieEvenement::SYSTEME,
        };
    }

    /**
     * Ce que l'événement fait à la masse monétaire du jeu.
     *
     *  - `creation`    : de l'or apparaît (un marchand paie, une quête récompense) ;
     *  - `destruction` : de l'or disparaît (un marchand encaisse, des frais sont prélevés) ;
     *  - `transfert`   : de l'or change de mains sans que le total bouge ;
     *  - `null`        : l'événement ne déplace pas d'or.
     *
     * C'est une propriété du TYPE, pas une requête : le SQL sait sommer `montant_or`, il ne
     * sait pas si un marchand est extérieur à l'économie des joueurs. Sans cette
     * classification, un tableau de bord additionnerait les transferts entre joueurs à la
     * création monétaire et conclurait à une inflation qui n'existe pas.
     *
     * ⚠️ `HDV_DEPOT` est un cas à part : son `montant_or` porte le PRIX demandé, qui n'est
     * ni créé ni détruit — ce qui disparaît, ce sont les frais, rangés dans
     * `contexte.fraisDepot`. Il est donc classé `destruction` mais son montant ne doit PAS
     * être lu dans `montant_or` (voir `EvenementJeuRepository::sommeFraisDepot()`).
     */
    public function fluxMonetaire(): ?string
    {
        return match ($this) {
            self::VENTE_PNJ, self::QUETE_TERMINEE => 'creation',
            self::ACHAT_PNJ, self::HDV_DEPOT => 'destruction',
            self::ECHANGE_CONCLU, self::HDV_ACHAT => 'transfert',
            default => null,
        };
    }

    /** @return list<self> les types dont le flux est celui demandé */
    public static function parFlux(string $flux): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type) => $type->fluxMonetaire() === $flux
        ));
    }

    /**
     * La phrase française qui décrit l'événement, rendue CÔTÉ SERVEUR.
     *
     * Elle vit ici et pas dans le front pour la même raison que `TypeCompteur::unite()` :
     * le client ne doit connaître aucun cas de l'enum en dur. Elle lit exclusivement ce
     * que `JournalNormalizer` a produit, donc des noms FIGÉS au moment de l'événement —
     * un objet supprimé depuis garde son nom dans la phrase.
     *
     * @param array{acteur?: ?array{pseudo?: string}, cibleUser?: ?array{pseudo?: string},
     *              cible?: ?array{nom?: string}, quantite?: int, montantOr?: int,
     *              contexte?: array} $evenement ligne normalisée
     */
    public function phrase(array $evenement): string
    {
        $acteur = $evenement['acteur']['pseudo'] ?? 'Quelqu\'un';
        $victime = $evenement['cibleUser']['pseudo'] ?? null;
        $cible = $evenement['cible']['nom'] ?? null;
        $quantite = (int) ($evenement['quantite'] ?? 0);
        $or = (int) ($evenement['montantOr'] ?? 0);
        $contexte = $evenement['contexte'] ?? [];

        $items = self::listerItems($contexte);
        $prix = self::formaterOr($or);

        return match ($this) {
            self::MONSTRE_TUE => $quantite > 1
                ? sprintf('%s a vaincu %s (×%d).', $acteur, $cible ?? 'un monstre', $quantite)
                : sprintf('%s a vaincu %s.', $acteur, $cible ?? 'un monstre'),

            self::BOSS_VAINCU => sprintf('%s a vaincu le boss %s.', $acteur, $cible ?? 'inconnu'),

            // Une seule ligne pour toutes les morts : la cause distingue le tueur d'un décès
            // sans responsable, sans quoi « X est mort » et « Y a tué X » seraient deux types.
            self::MORT_JOUEUR => match ($contexte['cause'] ?? 'inconnue') {
                'pvp' => sprintf('%s a tué %s.', $acteur, $victime ?? 'un joueur'),
                'monstre' => sprintf('%s a été tué par %s.', $victime ?? 'Un joueur', $cible ?? 'un monstre'),
                'boss' => sprintf('%s a été tué par le boss %s.', $victime ?? 'Un joueur', $cible ?? 'inconnu'),
                'zone_donjon' => sprintf('%s a péri dans une zone de donjon.', $victime ?? 'Un joueur'),
                default => sprintf('%s est mort.', $victime ?? 'Un joueur'),
            },

            self::XP_GAGNEE => sprintf('%s a gagné %d point%s d\'expérience.', $acteur, $quantite, $quantite > 1 ? 's' : ''),

            self::NIVEAU_ATTEINT => sprintf('%s atteint le niveau %d.', $acteur, $quantite),

            self::ECHANGE_CONCLU => sprintf(
                '%s a conclu un échange avec %s%s.',
                $acteur,
                $victime ?? 'un joueur',
                $items === null ? '' : ' : ' . $items
            ),

            self::HDV_DEPOT => sprintf(
                '%s a mis en vente %s pour %s%s.',
                $acteur,
                $items ?? 'un lot',
                $prix,
                isset($contexte['fraisDepot']) ? sprintf(' (frais : %s)', self::formaterOr((int) $contexte['fraisDepot'])) : ''
            ),

            self::HDV_ACHAT => sprintf(
                '%s a acheté %s à %s pour %s.',
                $acteur,
                $items ?? 'un lot',
                $victime ?? 'un vendeur',
                $prix
            ),

            self::HDV_RETRAIT => sprintf('%s a retiré %s de la vente.', $acteur, $items ?? 'un lot'),

            self::HDV_EXPIRATION => sprintf('L\'annonce de %s a expiré : %s rendu(s).', $acteur, $items ?? 'un lot'),

            self::ACHAT_PNJ => sprintf('%s a acheté %s pour %s.', $acteur, $items ?? 'un article', $prix),

            self::VENTE_PNJ => sprintf('%s a vendu %s pour %s.', $acteur, $items ?? 'un article', $prix),

            self::CRAFT_TERMINE => sprintf('%s a fabriqué %s.', $acteur, $cible ?? 'un objet'),

            self::RECOLTE => sprintf('%s a récolté %d × %s.', $acteur, max(1, $quantite), $cible ?? 'une ressource'),

            self::QUETE_TERMINEE => sprintf('%s a terminé la quête « %s ».', $acteur, $cible ?? 'inconnue'),

            self::CONNEXION => sprintf('%s s\'est connecté.', $acteur),

            self::GUILDE_CREEE => sprintf('%s a fondé la guilde « %s ».', $acteur, $contexte['nom'] ?? '?'),
            self::GUILDE_CANDIDATURE => sprintf('%s a candidaté à « %s ».', $acteur, $contexte['nom'] ?? '?'),
            self::GUILDE_ACCEPTATION => sprintf('%s a accepté %s dans « %s ».', $acteur, $victime ?? 'un joueur', $contexte['nom'] ?? '?'),
            self::GUILDE_REFUS => sprintf('%s a refusé la candidature de %s.', $acteur, $victime ?? 'un joueur'),
            self::GUILDE_DEPART => ($contexte['candidature'] ?? false)
                ? sprintf('%s a retiré sa candidature à « %s ».', $acteur, $contexte['nom'] ?? '?')
                : sprintf('%s a quitté la guilde « %s ».', $acteur, $contexte['nom'] ?? '?'),
            self::GUILDE_EXCLUSION => sprintf('%s a exclu %s de « %s ».', $acteur, $victime ?? 'un joueur', $contexte['nom'] ?? '?'),
            self::GUILDE_GRADE => ($contexte['transmission'] ?? false)
                ? sprintf('%s a transmis la baronnie à %s.', $acteur, $victime ?? 'un joueur')
                : sprintf('%s a nommé %s %s.', $acteur, $victime ?? 'un joueur', $contexte['grade'] ?? 'membre'),
            self::GUILDE_DISSOUTE => sprintf('%s a dissous la guilde « %s ».', $acteur, $contexte['nom'] ?? '?'),
        };
    }

    /**
     * « 3 × Minerai de fer, Épée courte » depuis `contexte.items`, ou null s'il n'y en a pas.
     *
     * Les noms sont ceux figés dans le contexte au moment de l'événement : `echange_ligne.item_id`
     * et `hotel_vente.item_id` n'ont pas de clé étrangère, donc aucune jointure SQL ne pourrait
     * les retrouver — et un objet supprimé depuis resterait de toute façon illisible.
     */
    private static function listerItems(array $contexte): ?string
    {
        $items = $contexte['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return null;
        }

        $morceaux = [];
        foreach ($items as $item) {
            $nom = $item['nom'] ?? 'objet inconnu';
            $quantite = (int) ($item['quantite'] ?? 1);
            $morceaux[] = $quantite > 1 ? sprintf('%d × %s', $quantite, $nom) : $nom;
        }

        return implode(', ', $morceaux);
    }

    private static function formaterOr(int $montant): string
    {
        return $montant === 1 ? '1 pièce d\'or' : sprintf('%d pièces d\'or', $montant);
    }
}
