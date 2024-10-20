<?php

namespace App\Repository;

use App\Entity\Pnj;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Pnj|null find($id, $lockMode = null, $lockVersion = null)
 * @method Pnj|null findOneBy(array $criteria, array $orderBy = null)
 * @method Pnj[]    findAll()
 * @method Pnj[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PnjRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pnj::class);
    }

    public function findAllAssoc(){
        return $this->createQueryBuilder('pnj')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

}
