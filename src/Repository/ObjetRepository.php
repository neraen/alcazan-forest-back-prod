<?php

namespace App\Repository;

use App\Entity\Objet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Objet|null find($id, $lockMode = null, $lockVersion = null)
 * @method Objet|null findOneBy(array $criteria, array $orderBy = null)
 * @method Objet[]    findAll()
 * @method Objet[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ObjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Objet::class);
    }

    /**
     * Ids des objets dont le nom contient `$terme`.
     *
     * Sert la recherche de l'hôtel des ventes : `hotel_vente.item_id` n'a pas de clé
     * étrangère, on ne peut donc pas joindre le nom en SQL. On résout d'abord le terme en
     * ids ici — la table de contenu est petite — puis on filtre les annonces dessus.
     *
     * @return int[]
     */
    public function findIdsParNom(string $terme): array
    {
        $lignes = $this->createQueryBuilder('objet')
            ->select('objet.id')
            ->where('objet.name LIKE :terme')
            ->setParameter('terme', '%' . $terme . '%')
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($lignes, 'id'));
    }

    // /**
    //  * @return Objet[] Returns an array of Objet objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Objet
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
