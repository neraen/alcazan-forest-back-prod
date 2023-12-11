<?php

namespace App\Repository;

use App\Entity\Carreau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Carreau|null find($id, $lockMode = null, $lockVersion = null)
 * @method Carreau|null findOneBy(array $criteria, array $orderBy = null)
 * @method Carreau[]    findAll()
 * @method Carreau[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CarreauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Carreau::class);
    }

    // /**
    //  * @return Carreau[] Returns an array of Carreau objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Carreau
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
