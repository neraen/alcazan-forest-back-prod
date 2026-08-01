<?php

namespace App\service;

use App\Config\JournalConfig;
use App\Entity\User;
use App\Enum\CategorieEvenement;
use App\Repository\EvenementJeuRepository;
use App\Repository\HistoriqueRepository;

/**
 * Le journal du JOUEUR : ce qu'il a fait et ce qu'il a subi, en français.
 *
 * ## Ce service a changé de métier
 *
 * Il ne fait plus qu'ÉCRIRE des phrases libres dans `historique` — il LIT désormais
 * `evenement_jeu`. La bascule est ce qui débloque enfin les catégories réelles que
 * `docs/REFONTE_PLAN.md` (phase 6) avait explicitement refusé d'inventer :
 *
 * > « Les 8 catégories de la maquette nécessiteraient un typage des événements côté back —
 * >   à faire si le gameplay l'ajoute. »
 *
 * C'est exactement ce que le journal a livré. Les deux seules catégories honnêtes possibles
 * jusqu'ici (« Mes actions » / « Subis ») deviennent les vraies : combat, économie,
 * progression, social, système.
 *
 * ## Pourquoi une UNION et aucun backfill
 *
 * Les lignes historiques sont des phrases interpolées (« Vous infligez 42 points de
 * dommages…<br /> »). Les re-typer demanderait des expressions régulières sur du texte
 * produit par du code qui a changé plusieurs fois, et le résultat serait de la **fausse
 * donnée structurée**. Elles sont donc servies telles quelles, dans une catégorie
 * « Archives » qui dit ce qu'elles sont : un héritage, pas une classification.
 *
 * Aucun risque de doublon : plus rien n'écrit dans `historique`. Les deux derniers appels
 * (morts face à un monstre et face à un boss) sont supprimés avec ce lot, l'événement
 * `MORT_JOUEUR` les couvrant intégralement, cause comprise.
 */
class HistoriqueService
{
    /** La catégorie des lignes héritées. Volontairement hors de `CategorieEvenement`. */
    public const CATEGORIE_ARCHIVES = 'archives';

    public function __construct(
        private readonly EvenementJeuRepository $evenementRepository,
        private readonly HistoriqueRepository $historiqueRepository,
        private readonly JournalNormalizer $normalizer,
    ) {}

    /**
     * Le journal d'un joueur, du plus récent au plus ancien.
     *
     * @return array{rows: list<array>, categories: list<array>}
     */
    public function pourJoueur(User $user): array
    {
        $lignes = array_merge(
            $this->evenements($user),
            $this->archives($user)
        );

        // Tri sur la date, les deux sources étant déjà triées mais pas entrelacées.
        usort($lignes, static fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return [
            'rows' => array_slice($lignes, 0, JournalConfig::JOURNAL_JOUEUR_MAX),
            'categories' => $this->categories(),
        ];
    }

    /** Les événements typés : ce que le joueur a fait ET subi. */
    private function evenements(User $user): array
    {
        $resultat = $this->evenementRepository->rechercher(
            (int) $user->getId(),
            null,
            null,
            null,
            1,
            JournalConfig::JOURNAL_JOUEUR_MAX
        );

        return array_map(
            static function (array $evenement) use ($user): array {
                // « Subi » veut dire : quelqu'un d'autre en est l'auteur et je suis la cible.
                // Une mort causée par l'environnement n'a pas d'acteur — elle est subie aussi.
                $acteurId = $evenement['acteur']['id'] ?? null;
                $cibleId = $evenement['cibleUser']['id'] ?? null;

                return [
                    'id' => 'e' . $evenement['id'],
                    'date' => $evenement['creeLe'],
                    'phrase' => $evenement['phrase'],
                    'categorie' => $evenement['categorie'],
                    'categorieLabel' => $evenement['categorieLabel'],
                    'type' => $evenement['type'],
                    'typeLabel' => $evenement['typeLabel'],
                    'subi' => $cibleId === (int) $user->getId() && $acteurId !== (int) $user->getId(),
                ];
            },
            $this->normalizer->normaliserPlusieurs($resultat['lignes'])
        );
    }

    /** Les lignes héritées, servies telles quelles. */
    private function archives(User $user): array
    {
        return array_map(
            static fn (array $ligne) => [
                'id' => 'h' . $ligne['id'],
                'date' => $ligne['date'] instanceof \DateTimeInterface
                    ? $ligne['date']->format('Y-m-d H:i:s')
                    : (string) $ligne['date'],
                // Le HTML des anciens messages est retiré : la page rend du texte, et un
                // `<br />` interpolé s'y afficherait littéralement.
                'phrase' => self::reparerEncodage(
                    trim(strip_tags(str_replace(['<br />', '<br/>', '<br>'], ' ', (string) $ligne['message'])))
                ),
                'categorie' => self::CATEGORIE_ARCHIVES,
                'categorieLabel' => 'Archives',
                'type' => null,
                'typeLabel' => null,
                'subi' => (bool) $ligne['isExternal'],
            ],
            $this->historiqueRepository->getAllRowsForPlayer((int) $user->getId())
        );
    }

    /**
     * Répare le double encodage des plus vieilles lignes d'`historique`.
     *
     * Une partie des messages a été écrite en UTF-8 réinterprété en Latin-1 : « infligé » y
     * est stocké « infligÃ© ». Le défaut est DANS LES DONNÉES (les lignes récentes sont
     * saines), et ne se voyait pas tant que cet écran n'affichait rien de lisible.
     *
     * Réparé à l'AFFICHAGE et non par une migration : ce sont des archives figées, et une
     * réécriture en masse d'un texte déjà abîmé se tenterait sans filet.
     *
     * Le test ne peut pas abîmer une ligne saine : on réinterprète la chaîne en Latin-1 pour
     * retrouver les octets d'origine, et on n'accepte le résultat QUE s'il est de l'UTF-8
     * valide. « assoiffé » (bien encodé) donnerait un octet isolé invalide → laissé tel
     * quel ; « infligÃ© » (doublement encodé) redonne « infligé » → réparé.
     */
    private static function reparerEncodage(string $texte): string
    {
        if (!str_contains($texte, 'Ã') && !str_contains($texte, 'Â')) {
            return $texte;
        }

        $octetsOrigine = @mb_convert_encoding($texte, 'ISO-8859-1', 'UTF-8');

        return ($octetsOrigine !== false && mb_check_encoding($octetsOrigine, 'UTF-8'))
            ? $octetsOrigine
            : $texte;
    }

    /** Les catégories proposées au filtre — aucune n'est en dur côté client. */
    private function categories(): array
    {
        $categories = array_map(
            static fn (CategorieEvenement $categorie) => [
                'valeur' => $categorie->value,
                'label' => $categorie->label(),
            ],
            CategorieEvenement::cases()
        );

        $categories[] = ['valeur' => self::CATEGORIE_ARCHIVES, 'label' => 'Archives'];

        return $categories;
    }
}
