<?php

namespace App\Repository;

use App\Entity\DonjonInstanceZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonInstanceZone>
 */
class DonjonInstanceZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonInstanceZone::class);
    }
}
