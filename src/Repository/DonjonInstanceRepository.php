<?php

namespace App\Repository;

use App\Entity\DonjonInstance;
use App\Entity\User;
use App\Enum\StatutInstanceDonjon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonInstance>
 */
class DonjonInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonInstance::class);
    }

    /**
     * L'instance non terminale dans laquelle le joueur est PRÉSENT (il est dedans),
     * membres et donjon préchargés. C'est la question posée à chaque chargement de carte :
     * elle doit rester en une requête.
     */
    public function findInstanceCourante(User $user): ?DonjonInstance
    {
        return $this->createQueryBuilder('i')
            ->addSelect('donjon', 'salle', 'membre', 'membreUser')
            ->join('i.donjon', 'donjon')
            ->leftJoin('donjon.salles', 'salle')
            ->join('i.membres', 'membre')
            ->join('membre.user', 'membreUser')
            ->where('i.statut = :enCours')
            ->andWhere('i.id IN (
                SELECT IDENTITY(m2.instance) FROM App\Entity\DonjonInstanceMembre m2
                WHERE m2.user = :user AND m2.present = true
            )')
            ->setParameter('enCours', StatutInstanceDonjon::EN_COURS)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Instances EN_COURS dont la durée max est dépassée — l'expiration est constatée
     * paresseusement (même patron que les échanges), pas par une tâche planifiée.
     *
     * @return DonjonInstance[]
     */
    public function findPerimees(\DateTimeImmutable $maintenant, int $limite = 20): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.statut = :enCours')
            ->andWhere('i.expireAt IS NOT NULL')
            ->andWhere('i.expireAt <= :maintenant')
            ->setParameter('enCours', StatutInstanceDonjon::EN_COURS)
            ->setParameter('maintenant', $maintenant)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
