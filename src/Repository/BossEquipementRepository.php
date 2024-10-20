<?php

namespace App\Repository;

use App\Entity\BossEquipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BossEquipement|null find($id, $lockMode = null, $lockVersion = null)
 * @method BossEquipement|null findOneBy(array $criteria, array $orderBy = null)
 * @method BossEquipement[]    findAll()
 * @method BossEquipement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BossEquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BossEquipement::class);
    }

    // /**
    //  * @return BossEquipement[] Returns an array of BossEquipement objects
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
    public function findOneBySomeField($value): ?BossEquipement
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
