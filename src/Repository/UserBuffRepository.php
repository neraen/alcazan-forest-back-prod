<?php

namespace App\Repository;

use App\Entity\UserBuff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method UserBuff|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserBuff|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserBuff[]    findAll()
 * @method UserBuff[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserBuffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBuff::class);
    }

    public function getActivePlayerBuff($userId){
        return $this->createQueryBuilder('userBuff')
            ->select('userBuff.dateDebut', 'buff.id', 'buff.name', 'buff.icone', 'buff.isCarac', 'buff.isDispell', 'buff.isBlocage',
                'buff.duree')
            ->leftJoin('userBuff.buff', 'buff')
            ->where('userBuff.user = '.$userId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    public function getAllBuffCaracteristiqueId($userId){
        return $this->createQueryBuilder('userBuff')
            ->select( 'buff.id')
            ->leftJoin('userBuff.buff', 'buff')
            ->where('userBuff.user = '.$userId)
            ->andWhere('buff.isCarac = 1')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return UserBuff[] Returns an array of UserBuff objects
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
    public function findOneBySomeField($value): ?UserBuff
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
