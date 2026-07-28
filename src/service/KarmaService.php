<?php

namespace App\service;

use App\Config\ArtisanatConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNIQUE point de mutation du karma. Ne flushe pas : l'appelant fournit la transaction
 * (même contrat que SacService, RecompenseService et MetierService).
 *
 * Le karma est la trace de la manière dont le joueur prend au monde : récolter proprement
 * le fait monter, saigner un gisement le fait descendre. Il est **volontairement distinct**
 * d'`honneur` (conduite en PvP) et d'`alignement` (camp choisi) — les fusionner ferait
 * qu'un pillard de filons perdrait sa réputation de duelliste.
 *
 * ⚠️ **Au lot 1, le karma n'a AUCUN effet de jeu** : il est stocké et affiché, rien de
 * plus (arbitrage du 26/07/2026, `docs/ARTISANAT_PLAN.md` §2). La conséquence est connue
 * et assumée : tant que rien ne s'y branche, la récolte intensive du lot 2 sera
 * strictement plus rentable que la récolte éthique, et le dilemme restera décoratif. Ce
 * service existe dès maintenant pour que le lot 6 n'ait qu'à brancher des effets sur un
 * point de mutation déjà unique, sans refactoring.
 *
 * La valeur est **bornée** : sans plafond, un joueur irréprochable pendant un mois se
 * mettrait à l'abri définitif de toute conséquence, et le curseur cesserait d'être un
 * choix pour devenir un acquis.
 */
class KarmaService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * Ajuste le karma de `$delta` (positif ou négatif) et renvoie l'état résultant.
     * La valeur est ramenée dans [KARMA_MIN, KARMA_MAX].
     *
     * @return array{karma: int, palier: string, delta: int} `delta` est l'ajustement
     *         RÉELLEMENT appliqué : il vaut 0 quand la borne était déjà atteinte, ce qui
     *         permet à l'appelant de ne pas annoncer un gain qui n'a pas eu lieu.
     */
    public function ajuster(User $user, int $delta): array
    {
        $avant = $user->getKarma();
        $apres = max(ArtisanatConfig::KARMA_MIN, min(ArtisanatConfig::KARMA_MAX, $avant + $delta));

        if ($apres !== $avant) {
            $user->setKarma($apres);
            $this->entityManager->persist($user);
        }

        return [
            'karma' => $apres,
            'palier' => ArtisanatConfig::palierKarma($apres),
            'delta' => $apres - $avant,
        ];
    }

    /** État du karma pour l'affichage (fiche de personnage). */
    public function decrire(User $user): array
    {
        return self::decrireValeur($user->getKarma());
    }

    /**
     * Même chose depuis une valeur brute, pour les lectures qui passent par une requête
     * scalaire plutôt que par l'entité (fiche de personnage).
     */
    public static function decrireValeur(int $karma): array
    {
        return [
            'valeur' => $karma,
            'palier' => ArtisanatConfig::palierKarma($karma),
            'min' => ArtisanatConfig::KARMA_MIN,
            'max' => ArtisanatConfig::KARMA_MAX,
        ];
    }
}
