<?php

namespace App\Repository;

use App\Entity\UserBoss;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method UserBoss|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserBoss|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserBoss[]    findAll()
 * @method UserBoss[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserBossRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBoss::class);
    }

    public function getUserBoss(int $userId, int $bossId){
        return $this->createQueryBuilder('userBoss')
            ->where('userBoss.user = '.$userId)
            ->andWhere('userBoss.boss = '.$bossId)
            ->getQuery()
            ->getSingleResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return UserBoss[] Returns an array of UserBoss objects
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
    public function findOneBySomeField($value): ?UserBoss
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
