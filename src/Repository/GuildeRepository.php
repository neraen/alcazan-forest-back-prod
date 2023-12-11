<?php

namespace App\Repository;

use App\Entity\Guilde;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Guilde|null find($id, $lockMode = null, $lockVersion = null)
 * @method Guilde|null findOneBy(array $criteria, array $orderBy = null)
 * @method Guilde[]    findAll()
 * @method Guilde[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GuildeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guilde::class);
    }

    public function getAllGuildesForPlayer($alignementId){
        return $this->createQueryBuilder('g')
            ->select('g.id', 'g.nom','g.description', 'g.icone', 'g.placeMax', 'g.niveau')
            ->where('g.alignement = '.$alignementId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return Guilde[] Returns an array of Guilde objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('g.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Guilde
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
