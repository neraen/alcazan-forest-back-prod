<?php

namespace App\Repository;

use App\Entity\ExperienceJoueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ExperienceJoueur|null find($id, $lockMode = null, $lockVersion = null)
 * @method ExperienceJoueur|null findOneBy(array $criteria, array $orderBy = null)
 * @method ExperienceJoueur[]    findAll()
 * @method ExperienceJoueur[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ExperienceJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperienceJoueur::class);
    }

    // /**
    //  * @return ExperienceJoueur[] Returns an array of ExperienceJoueur objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?ExperienceJoueur
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
