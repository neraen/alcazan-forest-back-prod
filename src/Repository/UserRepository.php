<?php

namespace App\Repository;

use App\Entity\CarteCarreau;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function getMinimalPlayerData($userId){
        return $this->createQueryBuilder('user')
            ->select('user.id as userId', 'user.currentLife', 'user.money', 'user.currentMana', 'user.maxLife', 'user.maxMana', 'user.pseudo', 'user.caseAbscisse',
                'user.caseOrdonnee', 'user.sexe', 'user.actionPoint', 'user.mouvementPoint', 'user.money', 'user.tutorialActive', 'alignement.nom as nomAlignement',
                'alignement.icone as iconeAlignement', 'alignement.id as idAlignement', 'level.niveau as niveau', 'classe.nom as nomClasse',
                'classe.id as classId', 'carte.id as mapId', 'guilde.nom as nomGuilde')
            ->leftJoin('user.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'level')
            ->leftJoin('user.alignement', 'alignement')
            ->leftJoin('user.classe', 'classe')
            ->leftJoin('user.map', 'carte')
            ->leftJoin('user.guilde', 'guilde')
            ->where('user.id = '.$userId)
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(UserInterface $user, string $newEncodedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newEncodedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }

    public function updateTargetLife(User $user, $life){
        return $this->createQueryBuilder('u')
            ->update(User::class, 'u')
            ->where("u.id = ". $user->getId())
            ->set('u.currentLife', $life)
            ->getQuery()
            ->execute();
    }

    public function getTargetedPlayer($userId){
        return $this->createQueryBuilder('user')
            ->select('user.currentLife',
                'user.currentMana',
                'user.maxLife',
                'user.maxMana',
                'user.pseudo',
                'alignement.nom as nomAlignement',
                'alignement.id as idAlignement',
                'level.niveau as niveau',
                'carteCarreau.abscisse as abscisseTarget',
                'carteCarreau.ordonnee as ordonneeTarget',
            )
            ->leftJoin('user.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'level')
            ->leftJoin('user.alignement', 'alignement')
            ->leftJoin(CarteCarreau::class, 'carteCarreau', Join::WITH ,'carteCarreau.joueur = user.id')
            ->where('user.id = '.$userId)
            ->getQuery()
            ->getSingleResult();
    }

    public function updateClasse($classe, $userId){
        return $this->createQueryBuilder('user')
            ->update(User::class, 'user')
            ->where("user.id = ". $userId)
            ->set('user.classe', $classe)
            ->getQuery()
            ->execute();
    }

    public function updateJoueurInfoAfterDeath( $summoningSickness, $mapId, $abscisse, $ordonne, $life, $mana, $userId){
        return $this->createQueryBuilder('user')
            ->update(User::class, 'user')
            ->where("user.id = ". $userId)
            ->set('user.summoningSickness', ':summoningSickness')
            ->set('user.map', $mapId)
            ->set('user.caseAbscisse', $abscisse)
            ->set('user.caseOrdonnee', $ordonne)
            ->set('user.currentLife', $life)
            ->set('user.currentMana', $mana)
            ->setParameter('summoningSickness',$summoningSickness, \Doctrine\DBAL\Types\Types::DATETIME_MUTABLE)
            ->getQuery()
            ->execute();
    }

    public function updatePlayerHonnor(int $userId, int $honnor){
        return $this->createQueryBuilder('user')
            ->update(User::class, 'user')
            ->where("user.id = ". $userId)
            ->set('user.honneur', ':honneur')
            ->setParameter('honneur', $honnor)
            ->getQuery()
            ->execute();
    }

    public function getDataForProfil(string $pseudo){
        return $this->createQueryBuilder('user')
            ->select('user.pseudo',
                'user.id as idJoueur',
                'alignement.nom as nomAlignement',
                'alignement.id as idAlignement',
                'guilde.nom as nomGuilde',
                'classe.nom as nomClasse',
                'level.niveau as niveau',
            )
            ->leftJoin('user.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'level')
            ->leftJoin('user.alignement', 'alignement')
            ->leftJoin('user.guilde', 'guilde')
            ->leftJoin('user.classe', 'classe')
            ->where("user.pseudo = '$pseudo'")
            ->getQuery()
            ->getSingleResult(AbstractQuery::HYDRATE_ARRAY);
    }

}
