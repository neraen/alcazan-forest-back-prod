<?php

namespace App\Repository;

use App\Entity\UserSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method UserSequence|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserSequence|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserSequence[]    findAll()
 * @method UserSequence[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSequence::class);
    }

    public function getActualSequenceForPnjAndPlayer($userId, $pnjId){
        return $this->createQueryBuilder('sequenceUser')
            ->select('dialogue.contenu as dialogContent', 'dialogue.titre as dialogTitle', 'action.name as actionName', 'action.api_link as actionLink', 'action.params as actionParams')
            ->leftJoin('sequenceUser.Sequence', 'sequence')
            ->leftJoin('sequence.dialogue', 'dialogue')
            ->leftJoin('sequence.sequenceActions', 'sequenceActions')
            ->leftJoin('sequenceActions.action', 'action')
            ->where('sequenceUser.user = '.$userId)
            ->andWhere('sequence.pnj = '.$pnjId)
            ->getQuery()
            ->getResult();
    }
}
