<?php

namespace App\Repository;

use App\Entity\Donjon;
use App\Entity\DonjonSalle;
use App\Enum\TypeSalleDonjon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonSalle>
 */
class DonjonSalleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonSalle::class);
    }

    public function findOneByCarte(int $carteId): ?DonjonSalle
    {
        return $this->findOneBy(['carte' => $carteId]);
    }

    /**
     * La salle d'entrée : celle marquée ENTREE, sinon la première dans l'ordre de
     * traversée (un donjon mal typé reste jouable).
     */
    public function findEntree(Donjon $donjon): ?DonjonSalle
    {
        return $this->createQueryBuilder('salle')
            ->addSelect('carte')
            ->join('salle.carte', 'carte')
            ->where('salle.donjon = :donjon')
            ->setParameter('donjon', $donjon)
            ->orderBy('CASE WHEN salle.type = :entree THEN 0 ELSE 1 END', 'ASC')
            ->setParameter('entree', TypeSalleDonjon::ENTREE->value)
            ->addOrderBy('salle.ordre', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
