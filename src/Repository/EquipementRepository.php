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
            ->select('equipement.nom, equipement.id, equipement.icone, equipement.prixRevente, equipement.description,
                equipement.prixAchat, equipement.level_min levelMin, positionEquipement.id positionEquipementId, 
                positionEquipement.name positionEquipementName, classe.id classeId, classe.nom classeName,
                rarity.id rarityId, rarity.name rarityName')
            ->leftJoin('equipement.positionEquipement', 'positionEquipement')
            ->leftJoin('equipement.classe', 'classe')
            ->leftJoin('equipement.rarity', 'rarity')
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
