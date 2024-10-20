<?php

namespace App\Repository;

use App\Entity\BossSortilege;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BossSortilege|null find($id, $lockMode = null, $lockVersion = null)
 * @method BossSortilege|null findOneBy(array $criteria, array $orderBy = null)
 * @method BossSortilege[]    findAll()
 * @method BossSortilege[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BossSortilegeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BossSortilege::class);
    }


    public function getBossSpellByLifePercent($bossId, $lifePercent){
        dump($lifePercent);
        return $this->createQueryBuilder('bossSpell')
            ->select(
                'nps.name',
                'nps.degatBase',
                'nps.coefPrincipal',
                'nps.coefSecondaire'
            )
            ->leftJoin('bossSpell.nonPlayerSortilege', 'nps')
            ->where('bossSpell.boss = '.$bossId)
            ->andWhere('bossSpell.lifePercent >= '.$lifePercent)
            ->andWhere('bossSpell.lifePercentMin <= '.$lifePercent)
            ->getQuery()
            ->getSingleResult();
    }
}
