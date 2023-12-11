<?php

namespace App\Repository;

use App\Entity\JoueurGrade;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method JoueurGrade|null find($id, $lockMode = null, $lockVersion = null)
 * @method JoueurGrade|null findOneBy(array $criteria, array $orderBy = null)
 * @method JoueurGrade[]    findAll()
 * @method JoueurGrade[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JoueurGradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurGrade::class);
    }

    // /**
    //  * @return JoueurGrade[] Returns an array of JoueurGrade objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?JoueurGrade
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
