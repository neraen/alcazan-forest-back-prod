<?php

namespace App\Repository;

use App\Entity\EvenementJeu;
use App\Enum\TypeEvenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès en SQL natif à `evenement_jeu` — jamais par l'unité de travail.
 *
 * L'écriture est un INSERT natif pour trois raisons développées dans `JournalService` ;
 * la lecture l'est parce qu'une table d'archive se lit par pages filtrées et agrégées,
 * ce que l'hydratation d'entités rendrait seulement plus lent.
 *
 * @extends ServiceEntityRepository<EvenementJeu>
 */
class EvenementJeuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvenementJeu::class);
    }

    private const COLONNES = 'id, type, acteur_id, cible_user_id, cible_type, cible_id, quantite, montant_or, contexte, cree_le';

    /**
     * Écrit un événement. La ligne est un tableau associatif déjà validé par le service.
     *
     * @param array{type: string, acteurId: ?int, cibleUserId: ?int, cibleType: ?string,
     *              cibleId: ?int, quantite: int, montantOr: int, contexte: ?string,
     *              creeLe: string} $ligne
     */
    public function inserer(array $ligne): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO evenement_jeu
                (type, acteur_id, cible_user_id, cible_type, cible_id, quantite, montant_or, contexte, cree_le)
             VALUES (:type, :acteur, :cibleUser, :cibleType, :cible, :quantite, :montantOr, :contexte, :creeLe)',
            [
                'type' => $ligne['type'],
                'acteur' => $ligne['acteurId'],
                'cibleUser' => $ligne['cibleUserId'],
                'cibleType' => $ligne['cibleType'],
                'cible' => $ligne['cibleId'],
                'quantite' => $ligne['quantite'],
                'montantOr' => $ligne['montantOr'],
                'contexte' => $ligne['contexte'],
                'creeLe' => $ligne['creeLe'],
            ]
        );
    }

    /**
     * Écrit plusieurs événements en UN seul INSERT multi-VALUES.
     *
     * Sert aux commandes de lot (expiration des annonces à l'hôtel des ventes) : une
     * centaine d'INSERT unitaires dans une boucle de scheduler coûte une centaine
     * d'allers-retours réseau pour rien.
     *
     * @param list<array> $lignes même forme que `inserer()`
     */
    public function insererPlusieurs(array $lignes): void
    {
        if ($lignes === []) {
            return;
        }

        $valeurs = [];
        $parametres = [];
        foreach (array_values($lignes) as $index => $ligne) {
            $valeurs[] = sprintf(
                '(:type%1$d, :acteur%1$d, :cibleUser%1$d, :cibleType%1$d, :cible%1$d, :quantite%1$d, :montantOr%1$d, :contexte%1$d, :creeLe%1$d)',
                $index
            );
            $parametres['type' . $index] = $ligne['type'];
            $parametres['acteur' . $index] = $ligne['acteurId'];
            $parametres['cibleUser' . $index] = $ligne['cibleUserId'];
            $parametres['cibleType' . $index] = $ligne['cibleType'];
            $parametres['cible' . $index] = $ligne['cibleId'];
            $parametres['quantite' . $index] = $ligne['quantite'];
            $parametres['montantOr' . $index] = $ligne['montantOr'];
            $parametres['contexte' . $index] = $ligne['contexte'];
            $parametres['creeLe' . $index] = $ligne['creeLe'];
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO evenement_jeu
                (type, acteur_id, cible_user_id, cible_type, cible_id, quantite, montant_or, contexte, cree_le)
             VALUES ' . implode(', ', $valeurs),
            $parametres
        );
    }

    /**
     * Une page du journal, du plus récent au plus ancien, plus le total pour la pagination.
     *
     * `$userId` filtre sur « a fait OU a subi » : c'est la question que pose réellement
     * l'administration, et les deux index `(acteur_id, cree_le)` et `(cible_user_id, cree_le)`
     * la servent par fusion d'index. Un seul index ne pourrait pas — c'est précisément
     * pourquoi « qui a subi » est une colonne et non une clé du contexte JSON.
     *
     * @param list<TypeEvenement>|null $types
     * @return array{lignes: list<array>, total: int}
     */
    public function rechercher(
        ?int $userId,
        ?array $types,
        ?\DateTimeImmutable $depuis,
        ?\DateTimeImmutable $jusqua,
        int $page,
        int $parPage
    ): array {
        $conditions = [];
        $parametres = [];
        $typesParametres = [];

        if ($userId !== null) {
            $conditions[] = '(acteur_id = :user OR cible_user_id = :user)';
            $parametres['user'] = $userId;
        }

        if ($types !== null && $types !== []) {
            $conditions[] = 'type IN (:types)';
            $parametres['types'] = array_map(static fn (TypeEvenement $type) => $type->value, $types);
            $typesParametres['types'] = ArrayParameterType::STRING;
        }

        if ($depuis !== null) {
            $conditions[] = 'cree_le >= :depuis';
            $parametres['depuis'] = $depuis->format('Y-m-d H:i:s');
        }

        if ($jusqua !== null) {
            $conditions[] = 'cree_le <= :jusqua';
            $parametres['jusqua'] = $jusqua->format('Y-m-d H:i:s');
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM evenement_jeu' . $where,
            $parametres,
            $typesParametres
        );

        $page = max(1, $page);
        $parPage = max(1, $parPage);

        // La pagination est interpolée et non liée : LIMIT/OFFSET n'acceptent pas de
        // paramètre en mode émulation, et les deux valeurs sont déjà des entiers bornés.
        $lignes = $connection->fetchAllAssociative(
            'SELECT ' . self::COLONNES . ' FROM evenement_jeu' . $where
            . sprintf(' ORDER BY cree_le DESC, id DESC LIMIT %d OFFSET %d', $parPage, ($page - 1) * $parPage),
            $parametres,
            $typesParametres
        );

        return ['lignes' => $lignes, 'total' => $total];
    }

    /**
     * Le nombre d'événements par jour et par type sur les `$jours` derniers jours.
     *
     * Le regroupement s'arrête au TYPE : la catégorie n'est pas en base, elle est dérivée
     * par `TypeEvenement::categorie()`. L'appelant agrège en PHP — c'est ce qui garantit
     * qu'un type qui change de rayon n'oblige à réécrire aucune donnée.
     *
     * @param list<TypeEvenement> $types vide = tous
     * @return list<array{jour: string, type: string, total: int}>
     */
    public function compterParJour(array $types, int $jours): array
    {
        $conditions = ['cree_le >= :depuis'];
        $parametres = ['depuis' => (new \DateTimeImmutable(sprintf('-%d days', max(1, $jours))))->format('Y-m-d 00:00:00')];
        $typesParametres = [];

        if ($types !== []) {
            $conditions[] = 'type IN (:types)';
            $parametres['types'] = array_map(static fn (TypeEvenement $type) => $type->value, $types);
            $typesParametres['types'] = ArrayParameterType::STRING;
        }

        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(cree_le) AS jour, type, COUNT(*) AS total
             FROM evenement_jeu
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY jour, type
             ORDER BY jour ASC',
            $parametres,
            $typesParametres
        );

        return array_map(
            static fn (array $ligne) => [
                'jour' => (string) $ligne['jour'],
                'type' => (string) $ligne['type'],
                'total' => (int) $ligne['total'],
            ],
            $lignes
        );
    }

    /**
     * Combien de fois `$acteurId` a tué `$victimeId` dans les `$heures` dernières heures.
     *
     * Sert l'anti-farm du PvP : c'est le SEUL endroit où le journal est une entrée de
     * gameplay et non un log. Couvert par l'index `(acteur_id, cree_le)`.
     *
     * ⚠️ Corollaire : `JournalConfig::RETENTION_JOURS` ne doit jamais descendre sous
     * `PvpConfig::FENETRE_ANTI_FARM_HEURES`, sinon la purge rouvre le farm.
     */
    public function compterMortsInfligees(int $acteurId, int $victimeId, int $heures): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*)
             FROM evenement_jeu
             WHERE type = 'mort_joueur'
               AND acteur_id = :acteur
               AND cible_user_id = :victime
               AND cree_le >= :depuis",
            [
                'acteur' => $acteurId,
                'victime' => $victimeId,
                'depuis' => (new \DateTimeImmutable(sprintf('-%d hours', max(1, $heures))))->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Combien de joueurs DISTINCTS se sont connectés depuis `$heures` heures.
     *
     * Lit les événements `CONNEXION`, écrits une fois par jour civil et par joueur : c'est la
     * seule source d'activité du jeu, `user.last_connexion` ne donnant que la dernière date.
     */
    public function joueursActifs(int $heures): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(DISTINCT acteur_id)
             FROM evenement_jeu
             WHERE type = 'connexion' AND cree_le >= :depuis",
            ['depuis' => (new \DateTimeImmutable(sprintf('-%d hours', max(1, $heures))))->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Somme de `montant_or` par type d'événement sur une fenêtre.
     *
     * C'est la matière première des flux monétaires : l'appelant décide ensuite quel type
     * est une création, une destruction ou un simple transfert — cette distinction est du
     * ressort du domaine, pas de la requête.
     *
     * @param list<TypeEvenement> $types
     * @return array<string, int> valeur du type => or cumulé
     */
    public function orParType(array $types, int $jours): array
    {
        if ($types === []) {
            return [];
        }

        $lignes = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT type, SUM(montant_or)
             FROM evenement_jeu
             WHERE type IN (:types) AND cree_le >= :depuis
             GROUP BY type',
            [
                'types' => array_map(static fn (TypeEvenement $type) => $type->value, $types),
                'depuis' => (new \DateTimeImmutable(sprintf('-%d days', max(1, $jours))))->format('Y-m-d 00:00:00'),
            ],
            ['types' => ArrayParameterType::STRING]
        );

        return array_map('intval', $lignes);
    }

    /**
     * L'or réellement DÉTRUIT par les frais de dépôt de l'hôtel des ventes.
     *
     * Requête à part parce que `hdv_depot.montant_or` porte le prix demandé et non les frais :
     * le prix n'est ni créé ni détruit, seuls les frais quittent le jeu. C'est le puits
     * monétaire documenté en §20, et c'est la première fois qu'il est mesurable.
     *
     * `JSON_EXTRACT` sur un ensemble déjà restreint par `(type, cree_le)` : le coût reste
     * celui de la fenêtre, pas celui de la table.
     */
    public function sommeFraisDepot(int $jours): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(JSON_EXTRACT(contexte, '$.fraisDepot')), 0)
             FROM evenement_jeu
             WHERE type = 'hdv_depot' AND cree_le >= :depuis AND contexte IS NOT NULL",
            ['depuis' => (new \DateTimeImmutable(sprintf('-%d days', max(1, $jours))))->format('Y-m-d 00:00:00')]
        );
    }

    /**
     * Les acteurs les plus actifs pour un ensemble de types.
     *
     * @param list<TypeEvenement> $types
     * @return list<array{userId: int, pseudo: string, total: int, montantOr: int}>
     */
    public function topActeurs(array $types, int $jours, int $limite): array
    {
        if ($types === []) {
            return [];
        }

        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT e.acteur_id AS userId, u.pseudo, COUNT(*) AS total, SUM(e.montant_or) AS montantOr
                 FROM evenement_jeu e
                 JOIN user u ON u.id = e.acteur_id
                 WHERE e.type IN (:types) AND e.cree_le >= :depuis
                 GROUP BY e.acteur_id, u.pseudo
                 ORDER BY montantOr DESC, total DESC
                 LIMIT %d',
                max(1, $limite)
            ),
            [
                'types' => array_map(static fn (TypeEvenement $type) => $type->value, $types),
                'depuis' => (new \DateTimeImmutable(sprintf('-%d days', max(1, $jours))))->format('Y-m-d 00:00:00'),
            ],
            ['types' => ArrayParameterType::STRING]
        );

        return array_map(
            static fn (array $ligne) => [
                'userId' => (int) $ligne['userId'],
                'pseudo' => (string) $ligne['pseudo'],
                'total' => (int) $ligne['total'],
                'montantOr' => (int) $ligne['montantOr'],
            ],
            $lignes
        );
    }

    /**
     * Les objets qui ont le plus circulé, comptés depuis `contexte.items`.
     *
     * ⚠️ L'agrégation se fait en PHP et NON en SQL, et c'est imposé par le modèle :
     * `echange_ligne.item_id` et `hotel_vente.item_id` n'ont pas de clé étrangère, donc
     * aucune jointure ne ramènerait le nom. C'est précisément pour ça que le journal FIGE le
     * nom dans son contexte au moment du fait. La contrepartie est qu'on ne peut pas
     * `GROUP BY` en base : on lit les lignes de la fenêtre et on les regroupe ici.
     *
     * @param list<TypeEvenement> $types
     * @return list<array{cle: string, nom: string, quantite: int, occurrences: int}>
     */
    public function topItems(array $types, int $jours, int $limite): array
    {
        if ($types === []) {
            return [];
        }

        $lignes = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT contexte
             FROM evenement_jeu
             WHERE type IN (:types) AND cree_le >= :depuis AND contexte IS NOT NULL',
            [
                'types' => array_map(static fn (TypeEvenement $type) => $type->value, $types),
                'depuis' => (new \DateTimeImmutable(sprintf('-%d days', max(1, $jours))))->format('Y-m-d 00:00:00'),
            ],
            ['types' => ArrayParameterType::STRING]
        );

        $cumul = [];
        foreach ($lignes as $brut) {
            $contexte = json_decode((string) $brut, true);
            foreach ($contexte['items'] ?? [] as $item) {
                if (!isset($item['type'], $item['id'])) {
                    continue;
                }
                $cle = $item['type'] . ':' . $item['id'];
                $cumul[$cle] ??= [
                    'cle' => $cle,
                    'nom' => (string) ($item['nom'] ?? 'Objet inconnu'),
                    'quantite' => 0,
                    'occurrences' => 0,
                ];
                $cumul[$cle]['quantite'] += max(1, (int) ($item['quantite'] ?? 1));
                ++$cumul[$cle]['occurrences'];
            }
        }

        usort($cumul, static fn (array $a, array $b) => $b['quantite'] <=> $a['quantite']);

        return array_slice(array_values($cumul), 0, max(1, $limite));
    }

    /**
     * Supprime les événements antérieurs à `$limite` et renvoie le nombre de lignes effacées.
     *
     * Par LOTS, et c'est le point : un DELETE unique de plusieurs centaines de milliers de
     * lignes tient un verrou long et gonfle le binlog. Une boucle de petits lots rend la
     * purge interruptible et invisible en jeu.
     */
    public function supprimerAvant(\DateTimeImmutable $limite, int $lot = 5000): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $lot = max(1, $lot);
        $total = 0;

        do {
            $supprimees = (int) $connection->executeStatement(
                sprintf('DELETE FROM evenement_jeu WHERE cree_le < :limite LIMIT %d', $lot),
                ['limite' => $limite->format('Y-m-d H:i:s')]
            );
            $total += $supprimees;
        } while ($supprimees > 0);

        return $total;
    }
}
