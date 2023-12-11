<?php

namespace App\Repository;

use App\Entity\JoueurCaracteristiqueBonus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method JoueurCaracteristiqueBonus|null find($id, $lockMode = null, $lockVersion = null)
 * @method JoueurCaracteristiqueBonus|null findOneBy(array $criteria, array $orderBy = null)
 * @method JoueurCaracteristiqueBonus[]    findAll()
 * @method JoueurCaracteristiqueBonus[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JoueurCaracteristiqueBonusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurCaracteristiqueBonus::class);
    }

    // /**
    //  * @return JoueurCaracteristiqueBonus[] Returns an array of JoueurCaracteristiqueBonus objects
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
    public function findOneBySomeField($value): ?JoueurCaracteristiqueBonus
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
