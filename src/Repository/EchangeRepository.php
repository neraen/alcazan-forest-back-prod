<?php

namespace App\Repository;

use App\Entity\Echange;
use App\Entity\User;
use App\Enum\StatutEchange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Echange|null find($id, $lockMode = null, $lockVersion = null)
 * @method Echange|null findOneBy(array $criteria, array $orderBy = null)
 * @method Echange[]    findAll()
 * @method Echange[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EchangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Echange::class);
    }

    /** La session active (EN_ATTENTE ou OUVERT) où le joueur est engagé, s'il y en a une. */
    public function findSessionActive(User $user): ?Echange
    {
        return $this->createQueryBuilder('echange')
            ->where('echange.joueurUn = :user OR echange.joueurDeux = :user')
            ->andWhere('echange.statut IN (:statuts)')
            ->setParameter('user', $user)
            ->setParameter('statuts', [StatutEchange::EN_ATTENTE, StatutEchange::OUVERT])
            ->orderBy('echange.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Invitations en attente DESTINÉES au joueur (il est joueurDeux). */
    public function findInvitationsRecues(User $user): array
    {
        return $this->createQueryBuilder('echange')
            ->where('echange.joueurDeux = :user')
            ->andWhere('echange.statut = :statut')
            ->setParameter('user', $user)
            ->setParameter('statut', StatutEchange::EN_ATTENTE)
            ->orderBy('echange.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Echange[] sessions non terminées dont l'expiration est dépassée */
    public function findExpirees(): array
    {
        return $this->createQueryBuilder('echange')
            ->where('echange.statut IN (:statuts)')
            ->andWhere('echange.expiresAt < :maintenant')
            ->setParameter('statuts', [StatutEchange::EN_ATTENTE, StatutEchange::OUVERT])
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
