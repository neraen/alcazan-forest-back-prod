<?php

namespace App\Repository;

use App\Entity\Donjon;
use App\Entity\DonjonVerrou;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonVerrou>
 */
class DonjonVerrouRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonVerrou::class);
    }

    /** Le verrou du joueur sur ce donjon pour le jour de donjon courant, s'il existe. */
    public function findPourJour(User $user, Donjon $donjon, \DateTimeImmutable $jourReset): ?DonjonVerrou
    {
        return $this->createQueryBuilder('v')
            // « instance » est un mot réservé du DQL : l'alias doit être autre chose.
            ->addSelect('inst')
            ->join('v.instance', 'inst')
            ->where('v.user = :user')
            ->andWhere('v.donjon = :donjon')
            ->andWhere('v.jourReset = :jour')
            ->setParameter('user', $user)
            ->setParameter('donjon', $donjon)
            ->setParameter('jour', $jourReset)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
