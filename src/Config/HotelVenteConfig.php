<?php

namespace App\Config;

use App\Enum\TriHotelVente;

/**
 * Règles chiffrées de l'hôtel des ventes, en UN seul endroit — même contrat
 * qu'ArtisanatConfig : tout ce qui est un curseur d'équilibrage vit ici plutôt qu'en dur
 * dans la machine à états.
 *
 * Le modèle économique retenu est celui des FRAIS DE DÉPÔT : le vendeur paie à la mise en
 * vente, jamais au moment de la vente, et n'est pas remboursé si son lot ne part pas. C'est
 * ce qui fait de l'hôtel un puits monétaire (l'or prélevé disparaît du jeu) et ce qui rend
 * coûteux le dépôt d'annonces à prix délirants — une commission prélevée seulement à la
 * vente ne coûte rien à celui qui affiche n'importe quel prix et encombre le catalogue.
 */
final class HotelVenteConfig
{
    /**
     * Part du prix demandé prélevée au dépôt, et plancher en pièces d'or.
     *
     * Le plancher existe pour qu'une annonce à 1 or ne soit jamais gratuite : sans lui,
     * `ceil(1 * 0.05)` vaudrait certes 1, mais tout ajustement du taux vers le bas rouvrirait
     * la porte au dépôt en masse de lots sans valeur, seul moyen connu de saturer un
     * catalogue.
     */
    public const TAUX_FRAIS_DEPOT = 0.05;
    public const FRAIS_MINIMUM = 1;

    /** Durée de vie d'une annonce, à l'issue de laquelle l'invendu retourne au vendeur. */
    public const DUREE_VENTE = '+48 hours';

    /**
     * Annonces simultanément EN_VENTE par joueur.
     *
     * C'est le seul frein réel au squat du catalogue : les frais de dépôt renchérissent le
     * spam mais ne l'empêchent pas pour un joueur riche. Ce plafond est aussi ce qui rend
     * acceptable le choix du lot indivisible (vendre à l'unité = plusieurs annonces).
     */
    public const ANNONCES_MAX_PAR_JOUEUR = 10;

    public const PRIX_MIN = 1;
    public const PRIX_MAX = 1000000;

    /** Taille d'une page du catalogue. */
    public const ANNONCES_PAR_PAGE = 50;

    /**
     * Frais réellement dus pour un prix demandé. Arrondi au supérieur : l'hôtel ne rend pas
     * la monnaie, et un arrondi au plus proche ferait des paliers gratuits.
     */
    public static function fraisDepot(int $prix): int
    {
        return max(self::FRAIS_MINIMUM, (int) ceil($prix * self::TAUX_FRAIS_DEPOT));
    }

    /**
     * Tous les curseurs, pour le payload front.
     *
     * Le client affiche les frais en direct pendant la saisie du prix ; il doit le faire avec
     * les chiffres du serveur, jamais avec des constantes recopiées, sans quoi retoucher
     * l'équilibrage ici mentirait à l'écran jusqu'au prochain déploiement du front. Le serveur
     * recalcule de toute façon au dépôt : ce qui descend ici n'autorise rien.
     */
    public static function curseurs(): array
    {
        return [
            'tauxFrais' => self::TAUX_FRAIS_DEPOT,
            'fraisMinimum' => self::FRAIS_MINIMUM,
            'dureeHeures' => self::dureeEnHeures(),
            'annoncesMax' => self::ANNONCES_MAX_PAR_JOUEUR,
            'prixMin' => self::PRIX_MIN,
            'prixMax' => self::PRIX_MAX,
            'parPage' => self::ANNONCES_PAR_PAGE,
            'tris' => TriHotelVente::options(),
        ];
    }

    /** Durée de vie exprimée en heures, pour l'affichage (« votre lot reste 48 h en vente »). */
    public static function dureeEnHeures(): int
    {
        $maintenant = new \DateTimeImmutable();

        return (int) round(($maintenant->modify(self::DUREE_VENTE)->getTimestamp() - $maintenant->getTimestamp()) / 3600);
    }
}
