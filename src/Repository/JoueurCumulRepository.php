<?php

namespace App\Repository;

use App\Entity\JoueurCumul;
use App\Entity\User;
use App\Enum\TypeCumul;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JoueurCumul>
 */
class JoueurCumulRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurCumul::class);
    }

    /**
     * Ajoute `$pas` à un cumul et renvoie la valeur atteinte.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` en SQL natif, jamais un read-modify-write — même
     * raisonnement que `CompteurJoueurRepository::incrementer` : deux monstres tués dans la
     * même seconde par deux requêtes concurrentes liraient la même valeur de départ et n'en
     * compteraient qu'un. **C'est l'index UNIQUE (user_id, cle) qui rend l'upsert possible ;
     * le retirer ferait perdre des incréments en silence, pas seulement l'intégrité.**
     *
     * L'écriture a lieu immédiatement, dans la transaction de l'appelant : le service ne
     * flushe pas, mais il n'a rien à flusher.
     */
    public function ajouterParId(int $userId, TypeCumul $cle, int $pas): int
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO joueur_cumul (user_id, cle, valeur, maj_at)
             VALUES (:user, :cle, :pas, :maintenant)
             ON DUPLICATE KEY UPDATE valeur = valeur + :pas, maj_at = :maintenant',
            [
                'user' => $userId,
                'cle' => $cle->value,
                'pas' => $pas,
                'maintenant' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        return $this->valeurParId($userId, $cle);
    }

    /** Valeur d'un cumul (0 s'il n'a jamais été alimenté). */
    public function valeurParId(int $userId, TypeCumul $cle): int
    {
        $valeur = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT valeur FROM joueur_cumul WHERE user_id = :user AND cle = :cle',
            ['user' => $userId, 'cle' => $cle->value]
        );

        return $valeur === false ? 0 : (int)$valeur;
    }

    /**
     * Tous les cumuls d'un joueur, en UNE requête.
     *
     * @return array<string, int> valeur de TypeCumul => total
     */
    public function valeurs(User $user): array
    {
        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT cle, valeur FROM joueur_cumul WHERE user_id = :user',
            ['user' => $user->getId()]
        );

        $valeurs = [];
        foreach ($lignes as $ligne) {
            $valeurs[(string)$ligne['cle']] = (int)$ligne['valeur'];
        }

        return $valeurs;
    }

    /**
     * Le haut du classement pour un cumul, joueurs exclus des classements écartés.
     *
     * Sert l'index `(cle, valeur)` : `WHERE cle = ? ORDER BY valeur DESC LIMIT n` est un
     * parcours d'index borné. Les jointures niveau/classe sont des `LEFT JOIN` — un compte
     * sans `niveau_joueur` doit apparaître au classement, pas en disparaître.
     *
     * @return list<array{userId: int, pseudo: string, niveau: ?int, classe: ?string, valeur: int}>
     */
    public function top(TypeCumul $cle, int $limite): array
    {
        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT u.id AS userId, u.pseudo, n.niveau, c.nom AS classe, jc.valeur
                 FROM joueur_cumul jc
                 JOIN user u ON u.id = jc.user_id
                 LEFT JOIN niveau_joueur nj ON nj.user_id = u.id
                 LEFT JOIN niveau n ON n.id = nj.niveau_id
                 LEFT JOIN classe c ON c.id = u.classe_id
                 WHERE jc.cle = :cle AND u.hors_classement = 0
                 ORDER BY jc.valeur DESC, u.pseudo ASC
                 LIMIT %d',
                max(1, $limite)
            ),
            ['cle' => $cle->value]
        );

        return array_map(self::ligneClassement(...), $lignes);
    }

    /**
     * Rang d'un joueur pour un cumul : le nombre de joueurs STRICTEMENT au-dessus, plus un.
     *
     * Conséquence assumée : les ex æquo partagent le même rang, et tous ceux qui n'ont
     * jamais alimenté le cumul partagent le dernier. C'est le comportement attendu — afficher
     * des rangs distincts pour des valeurs identiques demanderait un ordre arbitraire.
     *
     * @return array{rang: int, valeur: int}
     */
    public function rang(int $userId, TypeCumul $cle): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $valeur = $this->valeurParId($userId, $cle);

        $auDessus = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM joueur_cumul jc
             JOIN user u ON u.id = jc.user_id
             WHERE jc.cle = :cle AND u.hors_classement = 0 AND jc.valeur > :valeur',
            ['cle' => $cle->value, 'valeur' => $valeur]
        );

        return ['rang' => $auDessus + 1, 'valeur' => $valeur];
    }

    /** @return array{userId: int, pseudo: string, niveau: ?int, classe: ?string, valeur: int} */
    public static function ligneClassement(array $ligne): array
    {
        return [
            'userId' => (int) $ligne['userId'],
            'pseudo' => (string) $ligne['pseudo'],
            'niveau' => $ligne['niveau'] === null ? null : (int) $ligne['niveau'],
            'classe' => $ligne['classe'] === null ? null : (string) $ligne['classe'],
            'valeur' => (int) $ligne['valeur'],
        ];
    }

    /**
     * Écrase un cumul (et NON l'incrémente) : réservé au recalcul de maintenance.
     *
     * C'est ce qui rend les dénormalisations légitimes — une valeur dérivée n'est acceptable
     * que si on sait la reconstruire depuis sa source. Voir `app:cumuls:reparer`.
     */
    public function ecraserParId(int $userId, TypeCumul $cle, int $valeur): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO joueur_cumul (user_id, cle, valeur, maj_at)
             VALUES (:user, :cle, :valeur, :maintenant)
             ON DUPLICATE KEY UPDATE valeur = :valeur, maj_at = :maintenant',
            [
                'user' => $userId,
                'cle' => $cle->value,
                'valeur' => $valeur,
                'maintenant' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );
    }
}
