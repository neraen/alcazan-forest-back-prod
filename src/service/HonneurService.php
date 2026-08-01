<?php

namespace App\service;

use App\Config\PvpConfig;
use App\Entity\User;
use App\Enum\TypeEvenement;
use App\Repository\EvenementJeuRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNIQUE point de mutation de l'honneur. Ne flushe pas : l'appelant fournit la transaction
 * (même contrat que `KarmaService`, `SacService`, `CumulJoueurService`).
 *
 * Avant ce lot, l'honneur était la SEULE valeur de progression du jeu sans point de mutation
 * unique : `SpellService` écrivait directement via `UserRepository::updatePlayerHonnor`, avec
 * une formule trouée. C'est ce que ce service corrige.
 *
 * L'honneur est **volontairement distinct** du karma (rapport au monde) et de l'alignement
 * (camp choisi) : il ne mesure que la conduite en duel.
 */
class HonneurService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EvenementJeuRepository $evenementRepository,
    ) {}

    /**
     * Ajuste l'honneur de `$delta` et renvoie l'état résultant.
     *
     * @return array{honneur: int, palier: string, delta: int} `delta` est l'ajustement
     *         RÉELLEMENT appliqué — il vaut 0 quand la borne était déjà atteinte, ce qui
     *         permet à l'appelant de ne pas annoncer un gain qui n'a pas eu lieu.
     */
    public function ajuster(User $user, int $delta): array
    {
        // `honneur` était NULLABLE avant ce lot : `null + 20` donnait 20 sans prévenir.
        // La colonne est désormais NOT NULL, mais le repli garde le service correct sur une
        // entité chargée avant la migration.
        $avant = (int) ($user->getHonneur() ?? 0);
        $apres = PvpConfig::borner($avant + $delta, PvpConfig::HONNEUR_PLANCHER, PvpConfig::HONNEUR_PLAFOND);

        if ($apres !== $avant) {
            $user->setHonneur($apres);
            $this->entityManager->persist($user);
        }

        return [
            'honneur' => $apres,
            'palier' => self::palier($apres),
            'delta' => $apres - $avant,
        ];
    }

    /**
     * Applique le résultat d'un duel aux deux joueurs.
     *
     * ⚠️ `$farm` est un PARAMÈTRE et non un test refait ici, et c'est un piège déjà payé :
     * `dejaTueRecemment()` doit être interrogé AVANT que la mort ne soit consignée, sinon il
     * voit l'événement `MORT_JOUEUR` que le kill courant vient d'écrire et déclare farm dès
     * la première victoire. Or l'ordre est contraint dans l'autre sens — `diePlayer` finit
     * par un `entityManager->refresh()` de la victime, qui EFFACERAIT un honneur posé avant
     * lui. Il faut donc mesurer tôt et appliquer tard : seul l'appelant peut le faire.
     *
     * @param bool $farm résultat de `dejaTueRecemment()` mesuré avant la mort
     * @return array{vainqueur: array, vaincu: array, farm: bool}
     */
    public function appliquerVictoire(
        User $vainqueur,
        User $vaincu,
        int $niveauVainqueur,
        int $niveauVaincu,
        bool $farm
    ): array {
        if ($farm) {
            return [
                'vainqueur' => $this->decrire($vainqueur) + ['delta' => 0],
                'vaincu' => $this->decrire($vaincu) + ['delta' => 0],
                'farm' => true,
            ];
        }

        return [
            'vainqueur' => $this->ajuster($vainqueur, PvpConfig::gainVainqueur($niveauVainqueur, $niveauVaincu)),
            'vaincu' => $this->ajuster($vaincu, PvpConfig::perteVaincu($niveauVainqueur, $niveauVaincu)),
            'farm' => false,
        ];
    }

    /**
     * Ce vainqueur a-t-il déjà tué CETTE victime dans la fenêtre anti-farm ?
     *
     * Lu dans le journal, via l'index `(acteur_id, cree_le)`. **C'est le seul endroit où le
     * journal est une entrée de gameplay et non un simple log** — d'où la contrainte
     * documentée dans `JournalConfig` : la rétention ne doit jamais descendre sous cette
     * fenêtre, sinon la purge rouvre le farm.
     */
    public function dejaTueRecemment(User $vainqueur, User $vaincu): bool
    {
        return $this->evenementRepository->compterMortsInfligees(
            (int) $vainqueur->getId(),
            (int) $vaincu->getId(),
            PvpConfig::FENETRE_ANTI_FARM_HEURES
        ) > 0;
    }

    /** État de l'honneur pour l'affichage. */
    public function decrire(User $user): array
    {
        $honneur = (int) ($user->getHonneur() ?? 0);

        return [
            'honneur' => $honneur,
            'palier' => self::palier($honneur),
            'min' => PvpConfig::HONNEUR_PLANCHER,
            'max' => PvpConfig::HONNEUR_PLAFOND,
        ];
    }

    /**
     * Le palier lisible. Il vit ici et non dans le front pour la même raison que les
     * libellés de `TypeCumul` : le client ne doit connaître aucun seuil en dur.
     */
    public static function palier(int $honneur): string
    {
        return match (true) {
            $honneur <= -500 => 'Infâme',
            $honneur <= -150 => 'Brigand',
            $honneur < 150 => 'Sans renom',
            $honneur < 500 => 'Estimé',
            default => 'Champion',
        };
    }

    /** Le type d'événement que l'anti-farm interroge, exposé pour les tests. */
    public const EVENEMENT_SURVEILLE = TypeEvenement::MORT_JOUEUR;
}
