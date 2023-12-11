<?php

namespace App\Repository;

use App\Entity\CarteCarreau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CarteCarreau|null find($id, $lockMode = null, $lockVersion = null)
 * @method CarteCarreau|null findOneBy(array $criteria, array $orderBy = null)
 * @method CarteCarreau[]    findAll()
 * @method CarteCarreau[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CarteCarreauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CarteCarreau::class);
    }

    public function updatePlayerInCase($user){
        $this->createQueryBuilder('u')
            ->update(CarteCarreau::class, 'cc')
            ->set('cc.joueur',':joueur')
            ->where('cc.joueur = :id')
            ->setParameter('joueur',null)
            ->setParameter('id',$user->getId())
            ->getQuery()->execute();
    }

    public function findByCoordonnee($mapId, $abscisse, $ordonnee){
        return $this->createQueryBuilder('cc')
            ->where('cc.abscisse = '.$abscisse)
            ->andWhere('cc.ordonnee = '.$ordonnee)
            ->andWhere('cc.carte = '.$mapId)
            ->getQuery()
            ->getResult();
    }

    public function setPlayerOnCaseInAMap(int $carteId, int $abscisse, int $ordonnee, int $userId){
        $this->createQueryBuilder('u')
            ->update(CarteCarreau::class, 'cc')
            ->set('cc.joueur',':joueur')
            ->where('cc.carte = :carteId')
            ->andWhere('cc.abscisse = :abscisse')
            ->andWhere('cc.ordonnee = :ordonnee')
            ->setParameter('joueur',$userId)
            ->setParameter('carteId',$carteId)
            ->setParameter('abscisse',$abscisse)
            ->setParameter('ordonnee',$ordonnee)
            ->getQuery()->execute();
    }

    public function getAllCasesOfMap(int $mapId){
        return $this->createQueryBuilder('cc')
            ->select('carte.id as carteId', 'cc.id as carteCarreauId', 'cc.abscisse', 'cc.ordonnee',
                'cc.targetMapId',  'cc.targetWrap', 'user.id as userId', 'user.pseudo', 'user.sexe',  'classe.nom as nomClasse',
                'cc.isUsable', 'cc.isWrap', 'carreau.type as typeCarreau, level.niveau as niveau,alignement.nom as nomAlignement', 'alignement.icone as iconeAlignement',
                'monstreCarreau.id as hasMonstre','pnj.skin as pnjSkin', 'pnj.avatar as pnjAvatar', 'pnj.name as pnjName',
                'pnj.description as pnjDescription', 'pnj.id as pnjId', 'guilde.nom as nomGuilde', 'boss.name as bossName', 'boss.icone as bossSkin', 'boss.id as bossId',
                'action.name as actionName', 'action.api_link as actionLink', 'action.params as actionParams')
            ->leftJoin('cc.carreau', 'carreau')
            ->leftJoin('cc.carte', 'carte')
            ->leftJoin('cc.joueur', 'user')
            ->leftJoin('cc.monstreCarreau', 'monstreCarreau')
            ->leftJoin('cc.pnj', 'pnj')
            ->leftJoin('cc.action', 'action')
            ->leftJoin('cc.boss', 'boss')
            ->leftJoin('user.classe', 'classe')
            ->leftJoin('user.guilde', 'guilde')
            ->leftJoin('user.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'level')
            ->leftJoin('user.alignement', 'alignement')
            ->where('cc.carte = '.$mapId)
            ->orderBy('carteCarreauId')
            ->getQuery()
            ->getResult();
    }

    public function updateMapAndCases(array $data){
//        $this->createQueryBuilder('u')
//            ->update(CarteCarreau::class, 'cc')
//            ->set('cc.joueur',':joueur')
//            ->where('cc.carte = :carteId')
//            ->andWhere('cc.abscisse = :abscisse')
//            ->andWhere('cc.ordonnee = :ordonnee')
//            ->setParameter('joueur',$userId)
//            ->setParameter('carteId',$carteId)
//            ->setParameter('abscisse',$abscisse)
//            ->setParameter('ordonnee',$ordonnee)
//            ->getQuery()->execute();
    }

    public function getCasesInfoForSelect($mapId){
        return $this->createQueryBuilder('cc')
            ->select( 'cc.id as carteCarreauId', 'cc.abscisse', 'cc.ordonnee')
            ->where('cc.carte = '.$mapId)
            ->getQuery()
            ->getResult();
    }

    public function findByIdCarteCarreau($carteCarreauId){
        return $this->createQueryBuilder('cc')
            ->where('cc.id = '.$carteCarreauId)
            ->getQuery()
            ->getResult();
    }

}
