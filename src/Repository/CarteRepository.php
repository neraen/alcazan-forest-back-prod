<?php

namespace App\Repository;

use App\Entity\Carte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Carte|null find($id, $lockMode = null, $lockVersion = null)
 * @method Carte|null findOneBy(array $criteria, array $orderBy = null)
 * @method Carte[]    findAll()
 * @method Carte[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CarteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Carte::class);
    }

    public function getMapName($mapId){
        return $this->createQueryBuilder('carte')
            ->select('carte.id as carteId', 'carte.nom', 'carte.isInstance')
            ->where('carte.id = '.$mapId)
            ->getQuery()
            ->getSingleResult();
    }

    public function getAllMap(){
        return $this->createQueryBuilder('carte')
            ->select('carte.id as carteId', 'carte.nom')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);
    }

    public function findAllCimetiere(){
        return $this->createQueryBuilder('carte')
            ->where('carte.is_cimetiere = 1')
            ->getQuery()
            ->getResult();
    }

}
