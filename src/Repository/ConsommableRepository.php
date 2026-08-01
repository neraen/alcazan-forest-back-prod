<?php

namespace App\Repository;

use App\Entity\Consommable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Consommable|null find($id, $lockMode = null, $lockVersion = null)
 * @method Consommable|null findOneBy(array $criteria, array $orderBy = null)
 * @method Consommable[]    findAll()
 * @method Consommable[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConsommableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Consommable::class);
    }

    /**
     * Ids des consommables dont le nom contient `$terme`. Voir ObjetRepository::findIdsParNom
     * pour le pourquoi (recherche de l'hôtel des ventes, `item_id` sans clé étrangère).
     *
     * @return int[]
     */
    public function findIdsParNom(string $terme): array
    {
        $lignes = $this->createQueryBuilder('consommable')
            ->select('consommable.id')
            ->where('consommable.nom LIKE :terme')
            ->setParameter('terme', '%' . $terme . '%')
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($lignes, 'id'));
    }

    // /**
    //  * @return Consommable[] Returns an array of Consommable objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Consommable
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
