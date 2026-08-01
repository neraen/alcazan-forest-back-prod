<?php

namespace App\Repository;

use App\Entity\Guilde;
use App\Entity\JoueurGuilde;
use App\Entity\User;
use App\Enum\StatutGuilde;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method JoueurGuilde|null find($id, $lockMode = null, $lockVersion = null)
 * @method JoueurGuilde|null findOneBy(array $criteria, array $orderBy = null)
 * @method JoueurGuilde[]    findAll()
 * @method JoueurGuilde[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 *
 * @extends ServiceEntityRepository<JoueurGuilde>
 */
class JoueurGuildeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurGuilde::class);
    }

    /**
     * L'unique ligne du joueur — candidature ou appartenance — ou null.
     *
     * « L'unique » est garanti par l'index UNIQUE `(user_id)` : c'est ce qui permet à tout le
     * service de raisonner sur une seule ligne plutôt que sur une collection.
     */
    public function pourJoueur(User $user): ?JoueurGuilde
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Les lignes d'une guilde, membres ET candidats, avec de quoi les afficher.
     *
     * @return list<JoueurGuilde>
     */
    public function pourGuilde(Guilde $guilde): array
    {
        return $this->createQueryBuilder('jg')
            ->addSelect('u', 'classe', 'niveauJoueur', 'niveau')
            ->join('jg.user', 'u')
            ->leftJoin('u.classe', 'classe')
            ->leftJoin('u.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'niveau')
            ->where('jg.guilde = :guilde')
            ->setParameter('guilde', $guilde)
            ->orderBy('u.pseudo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Combien de MEMBRES compte une guilde — un candidat ne prend pas de place. */
    public function compterMembres(Guilde $guilde): int
    {
        return (int) $this->createQueryBuilder('jg')
            ->select('COUNT(jg.id)')
            ->where('jg.guilde = :guilde')
            ->andWhere('jg.statut = :statut')
            ->setParameter('guilde', $guilde)
            ->setParameter('statut', StatutGuilde::MEMBRE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Le nombre de membres de plusieurs guildes d'un coup, pour l'annuaire.
     *
     * @param list<int> $guildeIds
     * @return array<int, int> guildeId => nombre de membres
     */
    public function compterMembresParGuilde(array $guildeIds): array
    {
        if ($guildeIds === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('jg')
            ->select('IDENTITY(jg.guilde) AS guildeId, COUNT(jg.id) AS total')
            ->where('jg.guilde IN (:ids)')
            ->andWhere('jg.statut = :statut')
            ->setParameter('ids', $guildeIds)
            ->setParameter('statut', StatutGuilde::MEMBRE)
            ->groupBy('jg.guilde')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        $totaux = [];
        foreach ($lignes as $ligne) {
            $totaux[(int) $ligne['guildeId']] = (int) $ligne['total'];
        }

        return $totaux;
    }

    /**
     * Le classement des guildes : somme d'un cumul sur leurs MEMBRES.
     *
     * L'agrégat vit ici et non dans `JoueurCumulRepository` parce que la ligne classée est
     * une GUILDE et non un joueur — `joueur_cumul` n'a aucune notion de guilde.
     *
     * Les comptes exclus des classements le sont aussi de la somme de leur guilde : sans ça,
     * une guilde hébergeant un compte d'administration hériterait de ses chiffres de test.
     *
     * @return list<array{guildeId: int, nom: string, membres: int, valeur: int}>
     */
    public function classementParCumul(string $cle, int $limite): array
    {
        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                "SELECT g.id AS guildeId, g.nom, COUNT(DISTINCT jg.user_id) AS membres,
                        COALESCE(SUM(jc.valeur), 0) AS valeur
                 FROM joueur_guilde jg
                 JOIN guilde g ON g.id = jg.guilde_id
                 JOIN user u ON u.id = jg.user_id
                 LEFT JOIN joueur_cumul jc ON jc.user_id = jg.user_id AND jc.cle = :cle
                 WHERE jg.statut = 'membre' AND u.hors_classement = 0
                 GROUP BY g.id, g.nom
                 ORDER BY valeur DESC, g.nom ASC
                 LIMIT %d",
                max(1, $limite)
            ),
            ['cle' => $cle]
        );

        return array_map(
            static fn (array $ligne) => [
                'guildeId' => (int) $ligne['guildeId'],
                'nom' => (string) $ligne['nom'],
                'membres' => (int) $ligne['membres'],
                'valeur' => (int) $ligne['valeur'],
            ],
            $lignes
        );
    }
}
