<?php

namespace App\Repository;

use App\Entity\BossRecompense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BossRecompense|null find($id, $lockMode = null, $lockVersion = null)
 * @method BossRecompense|null findOneBy(array $criteria, array $orderBy = null)
 * @method BossRecompense[]    findAll()
 * @method BossRecompense[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BossRecompenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BossRecompense::class);
    }

    // /**
    //  * @return BossRecompense[] Returns an array of BossRecompense objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('b.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?BossRecompense
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
