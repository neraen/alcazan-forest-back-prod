<?php

namespace App\Repository;

use App\Entity\Donjon;
use App\Entity\DonjonGroupe;
use App\Entity\User;
use App\Enum\StatutGroupeDonjon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonGroupe>
 */
class DonjonGroupeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonGroupe::class);
    }

    /** Le groupe ouvert auquel le joueur est inscrit (meneur ou non). */
    public function findGroupeDuJoueur(User $user): ?DonjonGroupe
    {
        return $this->createQueryBuilder('g')
            ->addSelect('membre', 'membreUser', 'donjon')
            ->join('g.membres', 'membre')
            ->join('membre.user', 'membreUser')
            ->join('g.donjon', 'donjon')
            ->where('g.statut = :ouvert')
            ->andWhere('g.id IN (
                SELECT IDENTITY(m2.groupe) FROM App\Entity\DonjonGroupeMembre m2 WHERE m2.user = :user
            )')
            ->setParameter('ouvert', StatutGroupeDonjon::OUVERT)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les groupes ouverts d'un donjon, membres préchargés — c'est la liste que voit un
     * joueur qui clique sur la porte.
     *
     * @return DonjonGroupe[]
     */
    public function findGroupesOuverts(Donjon $donjon): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('membre', 'membreUser')
            ->join('g.membres', 'membre')
            ->join('membre.user', 'membreUser')
            ->where('g.statut = :ouvert')
            ->andWhere('g.donjon = :donjon')
            ->setParameter('ouvert', StatutGroupeDonjon::OUVERT)
            ->setParameter('donjon', $donjon)
            ->orderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return DonjonGroupe[] */
    public function findPerimes(\DateTimeImmutable $maintenant, int $limite = 20): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.statut = :ouvert')
            ->andWhere('g.expireAt <= :maintenant')
            ->setParameter('ouvert', StatutGroupeDonjon::OUVERT)
            ->setParameter('maintenant', $maintenant)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
