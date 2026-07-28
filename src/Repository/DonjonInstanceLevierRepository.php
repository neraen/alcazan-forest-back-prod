<?php

namespace App\Repository;

use App\Entity\DonjonInstanceLevier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonInstanceLevier>
 */
class DonjonInstanceLevierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonInstanceLevier::class);
    }
}
