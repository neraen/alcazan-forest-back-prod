<?php

namespace App\Repository;

use App\Entity\UserQuete;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method UserQuete|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserQuete|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserQuete[]    findAll()
 * @method UserQuete[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserQueteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserQuete::class);
    }

    // /**
    //  * @return UserQuete[] Returns an array of UserQuete objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?UserQuete
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
