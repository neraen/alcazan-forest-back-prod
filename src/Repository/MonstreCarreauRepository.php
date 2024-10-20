<?php

namespace App\Repository;

use App\Entity\MonstreCarreau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method MonstreCarreau|null find($id, $lockMode = null, $lockVersion = null)
 * @method MonstreCarreau|null findOneBy(array $criteria, array $orderBy = null)
 * @method MonstreCarreau[]    findAll()
 * @method MonstreCarreau[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MonstreCarreauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonstreCarreau::class);
    }

    public function getTargetedMonstre($monstreCarreauId){
        return $this->createQueryBuilder('monstreCarreau')
            ->select('monstreCarreau.quantity as quantiteMonstre', 'monstreCarreau.current_life as monstreLife', 'monstre.name as nomMonstre', 'monstre.skin as imageMonstre', 'monstre.maxLife as monstreLifeMax' )
            ->leftJoin('monstreCarreau.monstre', 'monstre')
            ->where('monstreCarreau.id = '.$monstreCarreauId)
            ->getQuery()
            ->getSingleResult();
    }

    public function doDamage($target, $life){
        return $this->createQueryBuilder('u')
            ->update(MonstreCarreau::class, 'monstreCarreau')
            ->where("monstreCarreau.id = ". $target->getId())
            ->set('monstreCarreau.current_life', $life)
            ->getQuery()
            ->execute();
    }

}
