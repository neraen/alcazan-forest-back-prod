<?php

namespace App\Repository;

use App\Entity\JoueurDialogue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method JoueurDialogue|null find($id, $lockMode = null, $lockVersion = null)
 * @method JoueurDialogue|null findOneBy(array $criteria, array $orderBy = null)
 * @method JoueurDialogue[]    findAll()
 * @method JoueurDialogue[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JoueurDialogueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurDialogue::class);
    }

    // /**
    //  * @return JoueurDialogue[] Returns an array of JoueurDialogue objects
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
    public function findOneBySomeField($value): ?JoueurDialogue
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
