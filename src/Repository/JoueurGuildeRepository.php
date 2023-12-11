<?php

namespace App\Repository;

use App\Entity\JoueurGuilde;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method JoueurGuilde|null find($id, $lockMode = null, $lockVersion = null)
 * @method JoueurGuilde|null findOneBy(array $criteria, array $orderBy = null)
 * @method JoueurGuilde[]    findAll()
 * @method JoueurGuilde[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JoueurGuildeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurGuilde::class);
    }

    public function getAllGuildesForPlayer($userId, $alignementId){
        return $this->createQueryBuilder('nj')
            ->select('n.niveau, n.experience as experienceMax, nj.experience as experienceActuelle')
            ->leftJoin('nj.niveau', 'n')
            ->where('nj.user = '.$userId)
            ->getQuery()
            ->getSingleResult(AbstractQuery::HYDRATE_ARRAY);
    }

    public function addPlayerToAGuilde($userId, $guildeId){

    }

    public function getAllPlayerOfAGuilde($guildeId){
        return $this->createQueryBuilder('jg')
            ->select('user.pseudo', 'jg.grade', 'classe.nom as classeName')
            ->leftJoin('jg.user', 'user' )
            ->leftJoin('user.classe', 'classe')
            ->where('jg.guilde = '.$guildeId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return JoueurGuilde[] Returns an array of JoueurGuilde objects
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
    public function findOneBySomeField($value): ?JoueurGuilde
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
