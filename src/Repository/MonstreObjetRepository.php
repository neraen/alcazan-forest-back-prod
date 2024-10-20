<?php

namespace App\Repository;

use App\Entity\MonstreObjet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method MonstreObjet|null find($id, $lockMode = null, $lockVersion = null)
 * @method MonstreObjet|null findOneBy(array $criteria, array $orderBy = null)
 * @method MonstreObjet[]    findAll()
 * @method MonstreObjet[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MonstreObjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonstreObjet::class);
    }

    // /**
    //  * @return MonstreObjet[] Returns an array of MonstreObjet objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?MonstreObjet
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
