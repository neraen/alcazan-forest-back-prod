<?php

namespace App\Repository;

use App\Entity\SequenceAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method SequenceAction|null find($id, $lockMode = null, $lockVersion = null)
 * @method SequenceAction|null findOneBy(array $criteria, array $orderBy = null)
 * @method SequenceAction[]    findAll()
 * @method SequenceAction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SequenceActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SequenceAction::class);
    }
}
