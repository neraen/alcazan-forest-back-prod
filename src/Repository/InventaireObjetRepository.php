<?php

namespace App\Repository;

use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method InventaireObjet|null find($id, $lockMode = null, $lockVersion = null)
 * @method InventaireObjet|null findOneBy(array $criteria, array $orderBy = null)
 * @method InventaireObjet[]    findAll()
 * @method InventaireObjet[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InventaireObjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventaireObjet::class);
    }

    public function getObjectQuantityInUserInventaire(int $userId, int $objetId): ?array
    {

        return $this->createQueryBuilder('inventaireObjet')
            ->select('inventaireObjet.quantity')
            ->leftJoin('inventaireObjet.inventaire', 'inventaire')
            ->where('inventaire.user = '.$userId)
            ->andWhere('inventaireObjet.objet = '.$objetId)
            ->getQuery();

    }

    // /**
    //  * @return InventaireObjet[] Returns an array of InventaireObjet objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?InventaireObjet
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
