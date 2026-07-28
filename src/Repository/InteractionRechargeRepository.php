<?php

namespace App\Repository;

use App\Entity\InteractionRecharge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InteractionRecharge>
 */
class InteractionRechargeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InteractionRecharge::class);
    }
}
