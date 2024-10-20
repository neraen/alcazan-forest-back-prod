<?php

namespace App\Repository;

use App\Entity\ActionFieldType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionFieldType>
 *
 * @method ActionFieldType|null find($id, $lockMode = null, $lockVersion = null)
 * @method ActionFieldType|null findOneBy(array $criteria, array $orderBy = null)
 * @method ActionFieldType[]    findAll()
 * @method ActionFieldType[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ActionFieldTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionFieldType::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(ActionFieldType $entity, bool $flush = true): void
    {
        $this->_em->persist($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(ActionFieldType $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function getFieldByType(int $typeId): array{
        return $this->createQueryBuilder('actionFieldType')
            ->select('actionField.id', 'actionField.name', 'actionField.link', 'actionField.type')
            ->where('actionFieldType.actionType = '.$typeId)
            ->leftJoin('actionFieldType.actionField', 'actionField')
            ->getQuery()
            ->getResult();
    }

    // /**
    //  * @return ActionFieldType[] Returns an array of ActionFieldType objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?ActionFieldType
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
