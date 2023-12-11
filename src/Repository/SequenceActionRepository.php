<?php

namespace App\Repository;

use App\Entity\SequenceAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
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

    public function getAllActionsBySequence(int $sequenceId): array{
        return $this->createQueryBuilder('sequenceAction')
            ->select('action.id as actionId', 'action.name as actionName', 'action.api_link as actionLink', 'action.params as actionParams',
               'actionType.id as actionTypeId', 'actionType.name as actionTypeName')
            ->leftJoin('sequenceAction.action', 'action')
            ->leftJoin('action.actionType', 'actionType')
            ->where('sequenceAction.sequence = '.$sequenceId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    public function getSequenceByAction(int $actionId): int{
        return $this->createQueryBuilder('sequenceAction')
            ->select('sequence.id')
            ->leftJoin('sequenceAction.action', 'action')
            ->leftJoin('sequenceAction.sequence', 'sequence')
            ->where('action.id = '.$actionId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
