<?php

namespace App\Repository;

use App\Entity\BossObjet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BossObjet|null find($id, $lockMode = null, $lockVersion = null)
 * @method BossObjet|null findOneBy(array $criteria, array $orderBy = null)
 * @method BossObjet[]    findAll()
 * @method BossObjet[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BossObjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BossObjet::class);
    }

    // /**
    //  * @return BossObjet[] Returns an array of BossObjet objects
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
    public function findOneBySomeField($value): ?BossObjet
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
