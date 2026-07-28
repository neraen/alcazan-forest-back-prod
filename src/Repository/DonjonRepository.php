<?php

namespace App\Repository;

use App\Entity\Donjon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Donjon>
 */
class DonjonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Donjon::class);
    }

    /**
     * Le donjon auquel appartient une carte, salles préchargées.
     * Une carte n'appartient qu'à un donjon (index unique sur donjon_salle.carte_id).
     */
    public function findByCarte(int $carteId): ?Donjon
    {
        return $this->createQueryBuilder('d')
            ->addSelect('salle', 'carte')
            ->join('d.salles', 'salle')
            ->join('salle.carte', 'carte')
            ->where('d.id IN (
                SELECT IDENTITY(s2.donjon) FROM App\Entity\DonjonSalle s2 WHERE s2.carte = :carteId
            )')
            ->setParameter('carteId', $carteId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
