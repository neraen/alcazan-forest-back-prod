<?php

namespace App\Repository;

use App\Entity\DonjonMecanique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonMecanique>
 */
class DonjonMecaniqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonMecanique::class);
    }
}
