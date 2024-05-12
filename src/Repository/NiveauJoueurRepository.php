<?php

namespace App\Repository;

use App\Entity\Niveau;
use App\Entity\NiveauJoueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NiveauJoueur|null find($id, $lockMode = null, $lockVersion = null)
 * @method NiveauJoueur|null findOneBy(array $criteria, array $orderBy = null)
 * @method NiveauJoueur[]    findAll()
 * @method NiveauJoueur[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NiveauJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NiveauJoueur::class);
    }

    public function getNiveauAndExperience($userId){
        return $this->createQueryBuilder('nj')
            ->select('n.niveau, n.experience as experienceMax, nj.experience as experienceActuelle')
            ->leftJoin('nj.niveau', 'n')
            ->where('nj.user = '.$userId)
            ->getQuery()
            ->getSingleResult();
    }

    public function getPlayerLevel($userId): ?int {
        return $this->createQueryBuilder('nj')
            ->select('n.niveau')
            ->leftJoin('nj.niveau', 'n')
            ->where('nj.user = '.$userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function addExperience($userId, $experience){
        $this->createQueryBuilder('niveauJoueur')
            ->update(niveauJoueur::class, 'niveauJoueur')
            ->set('niveauJoueur.experience',':experience')
            ->where('niveauJoueur.user = :id')
            ->setParameter('experience', $experience)
            ->setParameter('id', $userId)
            ->getQuery()->execute();
    }

    public function addExperienceAndUpLevel($userId, $experience, $level){
        $this->createQueryBuilder('niveauJoueur')
            ->update(niveauJoueur::class, 'niveauJoueur')
            ->set('niveauJoueur.experience',':experience')
            ->set('niveauJoueur.niveau', ':level')
            ->where('niveauJoueur.user = :id')
            ->setParameter('experience', $experience)
            ->setParameter('level', $level)
            ->setParameter('id', $userId)
            ->getQuery()->execute();
    }
}
