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
class UserRepository extends ServiceEntityRepository //implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function getMinimalPlayerData($userId){
        return $this->createQueryBuilder('user')
            ->select('user.id as userId', 'user.currentLife', 'user.money', 'user.currentMana', 'user.maxLife', 'user.maxMana', 'user.pseudo', 'user.caseAbscisse',
                'user.caseOrdonnee', 'user.sexe', 'user.actionPoint', 'user.mouvementPoint', 'user.money', 'user.tutorialActive', 'user.karma',
                'alignement.nom as nomAlignement',
                'alignement.icone as iconeAlignement', 'alignement.id as idAlignement', 'level.niveau as niveau', 'classe.nom as nomClasse',
                'classe.id as classId', 'carte.id as mapId', 'guilde.nom as nomGuilde')
            ->leftJoin('user.niveauJoueur', 'niveauJoueur')
            ->leftJoin('niveauJoueur.niveau', 'level')
            ->leftJoin('user.alignement', 'alignement')
            ->leftJoin('user.classe', 'classe')
            ->leftJoin('user.map', 'carte')
            ->leftJoin('user.joueurGuildes', 'appartenance', 'WITH', "appartenance.statut = 'membre'")
            ->leftJoin('appartenance.guilde', 'guilde')
            ->where('user.id = '.$userId)
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    /*public function upgradePassword(UserInterface $user, string $newEncodedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newEncodedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }*/

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

    /**
     * Le haut du classement pour un ÉTAT courant du joueur (`money`, `honneur`).
     *
     * ⚠️ `$colonne` est interpolée dans la requête : elle ne doit JAMAIS venir d'une entrée
     * client. Le seul appelant est `ClassementService`, qui l'obtient de
     * `CategorieClassement::colonneUser()` — un ensemble de valeurs clos par l'enum. La
     * garde ci-dessous rend l'invariant exécutable plutôt que documentaire.
     *
     * `COALESCE` parce que `user.honneur` est NULLABLE jusqu'au lot PvP : sans lui, les
     * comptes jamais engagés en duel sortiraient du classement au lieu d'y figurer à zéro.
     *
     * @return list<array{userId: int, pseudo: string, niveau: ?int, classe: ?string, valeur: int}>
     */
    public function topParEtat(string $colonne, int $limite): array
    {
        if (!in_array($colonne, ['money', 'honneur'], true)) {
            throw new \InvalidArgumentException("Colonne de classement non autorisée : $colonne");
        }

        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT u.id AS userId, u.pseudo, n.niveau, c.nom AS classe, COALESCE(u.%1$s, 0) AS valeur
                 FROM user u
                 LEFT JOIN niveau_joueur nj ON nj.user_id = u.id
                 LEFT JOIN niveau n ON n.id = nj.niveau_id
                 LEFT JOIN classe c ON c.id = u.classe_id
                 WHERE u.hors_classement = 0
                 ORDER BY valeur DESC, u.pseudo ASC
                 LIMIT %2$d',
                $colonne,
                max(1, $limite)
            )
        );

        return array_map(JoueurCumulRepository::ligneClassement(...), $lignes);
    }

    /**
     * Rang d'un joueur pour un état courant. Mêmes règles d'ex æquo que pour les cumuls.
     *
     * @return array{rang: int, valeur: int}
     */
    public function rangParEtat(int $userId, string $colonne): array
    {
        if (!in_array($colonne, ['money', 'honneur'], true)) {
            throw new \InvalidArgumentException("Colonne de classement non autorisée : $colonne");
        }

        $connection = $this->getEntityManager()->getConnection();

        $valeur = (int) $connection->fetchOne(
            sprintf('SELECT COALESCE(%s, 0) FROM user WHERE id = :id', $colonne),
            ['id' => $userId]
        );

        $auDessus = (int) $connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM user WHERE hors_classement = 0 AND COALESCE(%s, 0) > :valeur',
                $colonne
            ),
            ['valeur' => $valeur]
        );

        return ['rang' => $auDessus + 1, 'valeur' => $valeur];
    }

    /**
     * Tous les comptes, pour le rail de l'écran d'administration.
     *
     * Pas de pagination : la population du jeu tient dans une liste, et l'écran filtre côté
     * client. À revoir le jour où elle ne tiendra plus — ce sera un signe encourageant.
     *
     * @return list<array{id: int, pseudo: string, niveau: ?int, classe: ?string,
     *                    money: int, lastConnexion: ?string, horsClassement: bool}>
     */
    public function listerPourAdministration(): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT u.id, u.pseudo, u.money, u.hors_classement AS horsClassement,
                    DATE_FORMAT(u.last_connexion, \'%Y-%m-%d %H:%i\') AS lastConnexion,
                    n.niveau, c.nom AS classe
             FROM user u
             LEFT JOIN niveau_joueur nj ON nj.user_id = u.id
             LEFT JOIN niveau n ON n.id = nj.niveau_id
             LEFT JOIN classe c ON c.id = u.classe_id
             ORDER BY u.pseudo ASC'
        );
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
            ->leftJoin('user.joueurGuildes', 'appartenance', 'WITH', "appartenance.statut = 'membre'")
            ->leftJoin('appartenance.guilde', 'guilde')
            ->leftJoin('user.classe', 'classe')
            // Paramètre LIÉ : `$pseudo` vient du corps de la requête cliente
            // (`POST /joueur/data/profil`), donc d'une entrée non maîtrisée. La concaténation
            // précédente laissait injecter du DQL — même patron que le bug déjà corrigé dans
            // `HistoriqueRepository::getAllRowsForPlayer`.
            ->where('user.pseudo = :pseudo')
            ->setParameter('pseudo', $pseudo)
            ->getQuery()
            ->getSingleResult(AbstractQuery::HYDRATE_ARRAY);
    }

}
