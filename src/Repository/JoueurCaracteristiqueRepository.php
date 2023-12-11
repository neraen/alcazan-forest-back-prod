<?php

namespace App\Repository;

use App\Entity\JoueurCaracteristique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method JoueurCaracteristique|null find($id, $lockMode = null, $lockVersion = null)
 * @method JoueurCaracteristique|null findOneBy(array $criteria, array $orderBy = null)
 * @method JoueurCaracteristique[]    findAll()
 * @method JoueurCaracteristique[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JoueurCaracteristiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurCaracteristique::class);
    }

    public function updateCaracteristique($user, $caracteristiqueId,$value){
        return $this->createQueryBuilder('jc')
            ->update(JoueurCaracteristique::class, 'jc')
            ->where('jc.caracteristique = '.$caracteristiqueId)
            ->andWhere('jc.user = '.$user->getId())
            ->set('jc.points', $value)
            ->getQuery()
            ->execute();
    }

    public function getJoueurCaracteristiques($userId){
        return $this->createQueryBuilder('jc')
            ->select('jc.points, c.nom')
            ->leftJoin('jc.caracteristique', 'c')
            ->andWhere('jc.user = '.$userId)
            ->getQuery()
            ->getResult();
    }

    // /**
    //  * @return JoueurCaracteristique[] Returns an array of JoueurCaracteristique objects
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
    public function findOneBySomeField($value): ?JoueurCaracteristique
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
