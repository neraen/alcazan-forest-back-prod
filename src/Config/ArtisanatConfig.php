<?php

namespace App\Config;

use App\Enum\FamilleMetier;

/**
 * Règles chiffrées de l'artisanat, en UN seul endroit. Tout ce qui est un curseur
 * d'équilibrage vit ici plutôt qu'en dur dans les services : c'est ce qui permet de
 * retoucher le jeu sans relire la machine à états.
 *
 * Ce qui est du CONTENU (niveau max d'un métier, temps d'une recette, XP d'une récolte)
 * reste en base et ne doit pas remonter ici.
 */
final class ArtisanatConfig
{
    /**
     * Bornes du karma. Un compteur borné plutôt que libre : sans plafond, un joueur qui
     * récolte proprement pendant un mois se met à l'abri définitif de toute conséquence,
     * et le curseur cesse d'être un choix pour devenir un acquis.
     */
    public const KARMA_MIN = -1000;
    public const KARMA_MAX = 1000;

    /**
     * Paliers de karma, du plus bas au plus haut : [seuil minimal, libellé].
     *
     * Purement descriptif pour l'instant — le karma est stocké et affiché, il n'a AUCUN
     * effet de jeu (arbitrage du 26/07/2026, cf. docs/ARTISANAT_PLAN.md §2). Les paliers
     * existent dès maintenant pour que ce qui s'y branchera plus tard (conditions
     * d'accès, prix marchands) parle des mêmes seuils que ce que le joueur a lu.
     */
    private const PALIERS = [
        [self::KARMA_MIN, 'Pillard'],
        [-600, 'Rapace'],
        [-200, 'Mesuré'],   // bande neutre, centrée sur 0 : c'est là qu'on commence
        [200, 'Prévoyant'],
        [600, 'Gardien'],
    ];

    /** Libellé du palier correspondant à une valeur de karma. */
    public static function palierKarma(int $karma): string
    {
        $libelle = self::PALIERS[0][1];
        foreach (self::PALIERS as [$seuil, $nom]) {
            if ($karma >= $seuil) {
                $libelle = $nom;
            }
        }

        return $libelle;
    }

    /**
     * Combien de métiers de chaque famille un personnage peut apprendre.
     *
     * Le plafond n'a de sens que parce qu'apprendre est un ACTE EXPLICITE : tant que la
     * ligne `joueur_metier` se créait toute seule au premier gain d'XP, il n'y avait rien
     * à plafonner (cf. MetierService).
     */
    public static function plafond(FamilleMetier $famille): int
    {
        return match ($famille) {
            FamilleMetier::RECOLTE => 2,
            FamilleMetier::CRAFT => 3,
        };
    }

    /** @return array<string, int> famille => plafond, pour les payloads front. */
    public static function plafonds(): array
    {
        $plafonds = [];
        foreach (FamilleMetier::cases() as $famille) {
            $plafonds[$famille->value] = self::plafond($famille);
        }

        return $plafonds;
    }

    /**
     * @return array<string, string> famille => libellé affichable.
     *
     * Les libellés viennent du serveur pour la même raison que les types d'interaction :
     * le front ne doit connaître aucune famille en dur, sans quoi ajouter un cas à l'enum
     * afficherait « craft » en toutes lettres dans l'interface.
     */
    public static function famillesLabels(): array
    {
        $labels = [];
        foreach (FamilleMetier::cases() as $famille) {
            $labels[$famille->value] = $famille->label();
        }

        return $labels;
    }
}
