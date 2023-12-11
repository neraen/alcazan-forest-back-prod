<?php

namespace App\Repository;

use App\Entity\Wrap;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Wrap|null find($id, $lockMode = null, $lockVersion = null)
 * @method Wrap|null findOneBy(array $criteria, array $orderBy = null)
 * @method Wrap[]    findAll()
 * @method Wrap[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WrapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wrap::class);
    }

    // /**
    //  * @return Wrap[] Returns an array of Wrap objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Wrap
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
