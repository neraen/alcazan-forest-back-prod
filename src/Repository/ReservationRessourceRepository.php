<?php

namespace App\Repository;

use App\Entity\ReservationRessource;
use App\Entity\User;
use App\Enum\TypeRessource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ReservationRessource|null find($id, $lockMode = null, $lockVersion = null)
 * @method ReservationRessource|null findOneBy(array $criteria, array $orderBy = null)
 * @method ReservationRessource[]    findAll()
 * @method ReservationRessource[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ReservationRessourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationRessource::class);
    }

    public function sommeReservee(User $user, TypeRessource $type, int $itemId): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.quantite), 0)')
            ->where('reservation.user = :user')
            ->andWhere('reservation.type = :type')
            ->andWhere('reservation.itemId = :itemId')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('itemId', $itemId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return ReservationRessource[] */
    public function findByOrigine(string $origine, int $origineId): array
    {
        return $this->findBy(['origine' => $origine, 'origineId' => $origineId]);
    }
}
