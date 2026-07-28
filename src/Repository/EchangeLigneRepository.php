<?php

namespace App\Repository;

use App\Entity\EchangeLigne;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method EchangeLigne|null find($id, $lockMode = null, $lockVersion = null)
 * @method EchangeLigne|null findOneBy(array $criteria, array $orderBy = null)
 * @method EchangeLigne[]    findAll()
 * @method EchangeLigne[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EchangeLigneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EchangeLigne::class);
    }
}
