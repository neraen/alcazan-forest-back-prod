<?php

namespace App\Repository;

use App\Entity\UserEquipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method UserEquipement|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserEquipement|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserEquipement[]    findAll()
 * @method UserEquipement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserEquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEquipement::class);
    }

    public function getPlayerEquipementEquipe($userId){
        return $this->createQueryBuilder('userEquipement')
            ->select(
                'equipement.nom as nomEquipement',
                'equipement.id as idEquipement',
                'equipement.icone as imageEquipement',
                'equipement.description as descriptionEquipement',
                'equipement.level_min as levelMinEquipement',
                'equipement.prixRevente as prixReventeEquipement',
                'positionEquipement.name as position',
                'rarity.name as rarityName'
            )
            ->leftJoin('userEquipement.equipement', 'equipement')
            ->leftJoin('equipement.positionEquipement', 'positionEquipement')
            ->leftJoin('equipement.rarity', 'rarity')
            ->where('userEquipement.user = '.$userId)
            ->getQuery()
            ->getResult();
    }

    public function getEquipementEquipeByUserAndPosition($userId, $positionEquipementId){
        return $this->createQueryBuilder('userEquipement')
            ->leftJoin('userEquipement.equipement', 'equipement')
            ->where('userEquipement.user = '.$userId)
            ->andWhere('equipement.positionEquipement = '.$positionEquipementId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function unWearEquipement(){
       // return $this->createQueryBuilder('userEquipement')->delete()
    }
}
