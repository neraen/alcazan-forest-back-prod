<?php

namespace App\Repository;

use App\Entity\Boss;
use App\Entity\CarteCarreau;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Boss|null find($id, $lockMode = null, $lockVersion = null)
 * @method Boss|null findOneBy(array $criteria, array $orderBy = null)
 * @method Boss[]    findAll()
 * @method Boss[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BossRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Boss::class);
    }

    public function getTargetedBoss($bossId){
        return $this->createQueryBuilder('boss')
            ->select(
                'boss.actualLife as bossLife',
                'boss.maxLife as bossMaxLife',
                'boss.name as bossName',
                'boss.icone as bossSkin',
                'carteCarreau.abscisse as abscisseTarget',
                'carteCarreau.ordonnee as ordonneeTarget',
            )
            ->leftJoin(CarteCarreau::class, 'carteCarreau', Join::WITH ,'carteCarreau.boss = boss.id')
            ->where('boss.id = '.$bossId)
            ->getQuery()
            ->getSingleResult();
    }

    public function updateBossLife($bossId, $life){
        return $this->createQueryBuilder('boss')
            ->update(Boss::class, 'boss')
            ->where("boss.id = ". $bossId)
            ->set('boss.actualLife', $life)
            ->getQuery()
            ->execute();
    }

    // /**
    //  * @return Boss[] Returns an array of Boss objects
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
    public function findOneBySomeField($value): ?Boss
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
