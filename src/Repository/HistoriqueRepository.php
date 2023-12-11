<?php

namespace App\Repository;

use App\Entity\Historique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Historique|null find($id, $lockMode = null, $lockVersion = null)
 * @method Historique|null findOneBy(array $criteria, array $orderBy = null)
 * @method Historique[]    findAll()
 * @method Historique[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HistoriqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Historique::class);
    }

    public function insertHistoryForPlayer(int $userId, \DateTime $date, string $message){
        return $this->createQueryBuilder('historique')
            ->insert(Historique::class, 'historique')
            ->values(
                [
                    'user' => '?',
                    'date' => '?',
                    'message' => '?',
                ]
            )
            ->setParameter(0, $userId)
            ->setParameter(1, $date)
            ->setParameter(2, $message)
            ->getQuery()
            ->execute();
    }


    public function getAllRowsForPlayer(int $userId): array{
        return $this->createQueryBuilder('historique')
            ->select('historique.id', 'historique.message','historique.date', 'historique.isExternal')
            ->where('historique.user = '.$userId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    // /**
    //  * @return Historique[] Returns an array of Historique objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('h.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Historique
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
