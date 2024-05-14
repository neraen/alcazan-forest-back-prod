<?php

namespace App\Repository;

use App\Entity\Inventaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Inventaire|null find($id, $lockMode = null, $lockVersion = null)
 * @method Inventaire|null findOneBy(array $criteria, array $orderBy = null)
 * @method Inventaire[]    findAll()
 * @method Inventaire[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InventaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inventaire::class);
    }

   public function getPlayerEquipement($userId): array {
       return $this->createQueryBuilder('inventaire')
           ->select(
               'equipement.id as idEquipement',
               'equipement.nom as nomEquipement',
               'equipement.description as descriptionEquipement',
               'equipement.icone as imageEquipement',
               'equipement.prixRevente as prixReventeEquipement',
               'equipement.level_min as levelMinEquipement',
               'inventaireEquipements.quantity',
               'positionEquipement.name as position',
               'rarity.name as rarityName'
           )
           ->leftJoin('inventaire.inventaireEquipements', 'inventaireEquipements')
           ->leftJoin('inventaireEquipements.equipement', 'equipement')
           ->leftJoin('equipement.positionEquipement', 'positionEquipement')
           ->leftJoin('equipement.rarity', 'rarity')
           ->where('inventaire.user = '.$userId)
           ->getQuery()
           ->getResult();
   }

   public function getPlayerObjet($userId){
       return $this->createQueryBuilder('inventaire')
           ->select(
               'objet.id as idObjet',
               'objet.name as nomObjet',
               'objet.image as imageObjet',
               'objet.prix_vente as prixReventeObjet',
               'objet.description as descriptionObjet',
               'inventaireObjets.quantity'
           )
           ->leftJoin('inventaire.inventaireObjets', 'inventaireObjets')
           ->leftJoin('inventaireObjets.objet', 'objet')
           ->where('inventaire.user = '.$userId)
           ->getQuery()
           ->getResult();
   }

   public function getPlayerConsommable($userId){
       return $this->createQueryBuilder('inventaire')
           ->select(
               'consommable.id as idConsommable',
               'consommable.nom as nomConsommable',
               'consommable.icone as imageConsommable',
               'consommable.prixRevente as prixReventeConsommable',
               'consommable.description as descriptionConsommable',
               'inventaireConsommables.quantity'
           )
           ->leftJoin('inventaire.inventaireConsommables', 'inventaireConsommables')
           ->leftJoin('inventaireConsommables.consommable', 'consommable')
           ->where('inventaire.user = '.$userId)
           ->getQuery()
           ->getResult();
   }
}
