<?php

namespace App\Repository;

use App\Entity\PositionEquipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PositionEquipement|null find($id, $lockMode = null, $lockVersion = null)
 * @method PositionEquipement|null findOneBy(array $criteria, array $orderBy = null)
 * @method PositionEquipement[]    findAll()
 * @method PositionEquipement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PositionEquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionEquipement::class);
    }

    public function findAllAssociative(){
        return $this->createQueryBuilder('p')->getQuery()->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return PositionEquipement[] Returns an array of PositionEquipement objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?PositionEquipement
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
