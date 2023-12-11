<?php

namespace App\Repository;

use App\Entity\NonPlayerSortillege;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NonPlayerSortillege|null find($id, $lockMode = null, $lockVersion = null)
 * @method NonPlayerSortillege|null findOneBy(array $criteria, array $orderBy = null)
 * @method NonPlayerSortillege[]    findAll()
 * @method NonPlayerSortillege[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NonPlayerSortillegeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NonPlayerSortillege::class);
    }

    // /**
    //  * @return NonPlayerSortillege[] Returns an array of NonPlayerSortillege objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?NonPlayerSortillege
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
