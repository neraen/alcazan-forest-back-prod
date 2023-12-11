<?php

namespace App\Repository;

use App\Entity\Sortilege;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Sortilege|null find($id, $lockMode = null, $lockVersion = null)
 * @method Sortilege|null findOneBy(array $criteria, array $orderBy = null)
 * @method Sortilege[]    findAll()
 * @method Sortilege[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SortilegeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sortilege::class);
    }

    public function getSpellsByClassId($classId){
        return $this->createQueryBuilder('sortilege')
            ->select('sortilege.nom', 'sortilege.description','sortilege.type', 'sortilege.cooldown', 'sortilege.icone', 'sortilege.niveau', 'sortilege.portee', 'sortilege.id')
            ->where('sortilege.classe = '.$classId)
            ->getQuery()
            ->getResult();
    }

}
