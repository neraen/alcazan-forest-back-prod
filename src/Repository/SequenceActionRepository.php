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
            ->select('action.id as actionId', 'action.name as actionName', 'action.api_link as actionApiLink', 'action.params as actionParams',
               'action.quantity as quantity', 'action.message as actionMessage', 'actionType.id as actionTypeId', 'actionType.name as actionTypeName')
            ->leftJoin('sequenceAction.action', 'action')
            ->leftJoin('action.actionType', 'actionType')
            ->where('sequenceAction.sequence = '.$sequenceId)
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    public function getAllActionsBySequenceWithJoin(int $sequenceId): array{
        return $this->createQueryBuilder('sequenceAction')
            ->select('action.id as actionId', 'action.name as actionName', 'action.api_link as actionApiLink', 'action.params as actionParams',
                'action.message as actionMessage',
                'objet.id as objets', 'consommable.id as consommables', 'action.quantity as actionQuantity', 'equipement.id as equipements',
                'boss.id as bosses', 'monstre.id as monstres', 'pnj.id as pnjs', 'carte.id as cartes',
                'actionType.id as actionTypeId', 'actionType.name as actionTypeName')
            ->leftJoin('sequenceAction.action', 'action')
            ->leftJoin('action.actionType', 'actionType')
            ->leftJoin('action.objet', 'objet')
            ->leftJoin('action.consommable', 'consommable')
            ->leftJoin('action.equipement', 'equipement')
            ->leftJoin('action.boss', 'boss')
            ->leftJoin('action.carte', 'carte')
            ->leftJoin('action.pnj', 'pnj')
            ->leftJoin('action.monstre', 'monstre')
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
