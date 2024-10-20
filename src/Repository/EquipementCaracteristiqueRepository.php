<?php

namespace App\Repository;

use App\Entity\EquipementCaracteristique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method EquipementCaracteristique|null find($id, $lockMode = null, $lockVersion = null)
 * @method EquipementCaracteristique|null findOneBy(array $criteria, array $orderBy = null)
 * @method EquipementCaracteristique[]    findAll()
 * @method EquipementCaracteristique[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EquipementCaracteristiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipementCaracteristique::class);
    }

    public function getAllCaracteristiquesByIdEquipement($equipementId){
        return $this->createQueryBuilder('equipementCaracteristiques')
            ->select(
                'equipementCaracteristiques.valeur',
                'caracteristique.id',
                'caracteristique.nom'
            )
            ->leftJoin('equipementCaracteristiques.caracteristique', 'caracteristique')
            ->where('equipementCaracteristiques.equipement = '.$equipementId)
            ->getQuery()
            ->getResult();
    }



    // /**
    //  * @return EquipementCaracteristique[] Returns an array of EquipementCaracteristique objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?EquipementCaracteristique
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
