<?php

namespace App\Repository;

use App\Entity\Equipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Equipement|null find($id, $lockMode = null, $lockVersion = null)
 * @method Equipement|null findOneBy(array $criteria, array $orderBy = null)
 * @method Equipement[]    findAll()
 * @method Equipement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipement::class);
    }

    public function getAllEquipementGroupedByPosition(){
        return $this->createQueryBuilder('equipement')
            ->select('equipement.nom, equipement.id, positionEquipement.id, positionEquipement.name, caracteristique.nom, equipementCaracteristique.valeur')
            ->leftJoin('equipement.positionEquipement', 'positionEquipement')
            ->leftJoin('equipement.equipementCaracteristiques', 'equipementCaracteristique')
            ->leftJoin('equipementCaracteristique.caracteristique', 'caracteristique')
            ->getQuery()
            ->getResult();
    }

    // /**
    //  * @return Equipement[] Returns an array of Equipement objects
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
    public function findOneBySomeField($value): ?Equipement
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
