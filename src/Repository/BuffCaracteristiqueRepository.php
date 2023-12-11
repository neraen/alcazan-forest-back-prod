<?php

namespace App\Repository;

use App\Entity\BuffCaracteristique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BuffCaracteristique|null find($id, $lockMode = null, $lockVersion = null)
 * @method BuffCaracteristique|null findOneBy(array $criteria, array $orderBy = null)
 * @method BuffCaracteristique[]    findAll()
 * @method BuffCaracteristique[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BuffCaracteristiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuffCaracteristique::class);
    }

    public function getAllBuffCaracs(int $buffId){

        return $this->createQueryBuilder('buffCarac')
            ->select('buffCarac.value', 'caracteristique.nom')
            ->leftJoin('buffCarac.caracteristique', 'caracteristique')
            ->where('buffCarac.buff = '.$buffId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    public function getValueByBuffAndCarac(int $buffId, int $caracteristiqueId){
        return $this->createQueryBuilder('buffCarac')
            ->select('buffCarac.value')
            ->leftJoin('buffCarac.caracteristique', 'caracteristique')
            ->where('buffCarac.buff = '.$buffId)
            ->andWhere('buffCarac.caracteristique = '.$caracteristiqueId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return BuffCaracteristique[] Returns an array of BuffCaracteristique objects
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
    public function findOneBySomeField($value): ?BuffCaracteristique
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
