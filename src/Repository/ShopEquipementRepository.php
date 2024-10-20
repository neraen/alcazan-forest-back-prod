<?php

namespace App\Repository;

use App\Entity\ShopEquipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ShopEquipement|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShopEquipement|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShopEquipement[]    findAll()
 * @method ShopEquipement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShopEquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopEquipement::class);
    }


    public function getEquipementsShop($id){
        return $this->createQueryBuilder('se')
            ->select('equipement.id as idEquipement, equipement.nom as nomEquipement, equipement.icone, equipement.prixAchat, equipement.description descriptionEquipement',
                'equipement.level_min as levelMinEquipement','positionEquipement.name', 'rarity.name as rarityName', 'positionEquipement.name as position')
            ->leftJoin('se.equipement', 'equipement')
            ->leftJoin('equipement.positionEquipement', 'positionEquipement')
            ->leftJoin('equipement.rarity', 'rarity')
            ->where('se.shop = '.$id)
            ->getQuery()
            ->getResult();
    }

    // /**
    //  * @return ShopEquipement[] Returns an array of ShopEquipement objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?ShopEquipement
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
