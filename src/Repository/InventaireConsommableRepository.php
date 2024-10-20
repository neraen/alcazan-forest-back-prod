<?php

namespace App\Repository;

use App\Entity\InventaireConsommable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method InventaireConsommable|null find($id, $lockMode = null, $lockVersion = null)
 * @method InventaireConsommable|null findOneBy(array $criteria, array $orderBy = null)
 * @method InventaireConsommable[]    findAll()
 * @method InventaireConsommable[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InventaireConsommableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventaireConsommable::class);
    }

    // /**
    //  * @return InventaireConsommable[] Returns an array of InventaireConsommable objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?InventaireConsommable
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
