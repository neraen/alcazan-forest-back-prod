<?php

namespace App\Repository;

use App\Entity\Historique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Historique|null find($id, $lockMode = null, $lockVersion = null)
 * @method Historique|null findOneBy(array $criteria, array $orderBy = null)
 * @method Historique[]    findAll()
 * @method Historique[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HistoriqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Historique::class);
    }

    /**
     * Le nombre de lignes rendues au joueur.
     *
     * L'ancien code n'en posait AUCUNE, et sans ordre : l'endpoint renvoyait tout
     * l'historique du personnage dans l'ordre d'insertion, ce qui grossit sans fin.
     */
    private const LIGNES_MAX = 200;

    /**
     * Les dernières lignes d'historique d'un joueur, de la plus récente à la plus ancienne.
     *
     * Trois corrections par rapport à la version d'origine : le paramètre est LIÉ et non
     * concaténé dans le DQL (l'identifiant venait du jeton, donc ce n'était pas exploitable,
     * mais c'est le patron à ne surtout pas reprendre ailleurs), un `ORDER BY` explicite, et
     * une limite.
     */
    public function getAllRowsForPlayer(int $userId): array{
        return $this->createQueryBuilder('historique')
            ->select('historique.id', 'historique.message','historique.date', 'historique.isExternal')
            ->where('historique.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('historique.date', 'DESC')
            ->addOrderBy('historique.id', 'DESC')
            ->setMaxResults(self::LIGNES_MAX)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return Historique[] Returns an array of Historique objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('h.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Historique
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
