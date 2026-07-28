<?php

namespace App\Repository;

use App\Entity\DonjonInstanceMembre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DonjonInstanceMembre>
 */
class DonjonInstanceMembreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DonjonInstanceMembre::class);
    }

    /**
     * Les membres présents d'une instance qui se trouvent sur une carte donnée, avec
     * exactement les champs de joueur que produit CarteCarreauRepository::getAllCasesOfMap
     * — DonjonMapView les réinjecte tels quels dans les cases, le front ne voit aucune
     * différence entre une carte instanciée et une carte du monde ouvert.
     *
     * La position vient de `user` (source de vérité habituelle) : elle n'est pas dupliquée
     * dans la table des membres.
     */
    public function getMembresSurCarte(int $instanceId, int $carteId): array
    {
        return $this->createQueryBuilder('membre')
            ->select(
                'user.id as userId', 'user.pseudo', 'user.sexe',
                'user.caseAbscisse as abscisse', 'user.caseOrdonnee as ordonnee',
                'classe.nom as nomClasse', 'level.niveau as niveau',
                'alignement.nom as nomAlignement', 'alignement.icone as iconeAlignement',
                'guilde.nom as nomGuilde'
            )
            ->join('membre.user', 'user')
            ->leftJoin('user.classe', 'classe')
            ->leftJoin('user.guilde', 'guilde')
            ->leftJoin('user.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'level')
            ->leftJoin('user.alignement', 'alignement')
            ->where('membre.instance = :instanceId')
            ->andWhere('membre.present = true')
            ->andWhere('user.map = :carteId')
            ->setParameter('instanceId', $instanceId)
            ->setParameter('carteId', $carteId)
            ->getQuery()
            ->getResult();
    }
}
