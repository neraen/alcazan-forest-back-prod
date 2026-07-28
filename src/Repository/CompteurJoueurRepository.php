<?php

namespace App\Repository;

use App\Entity\CompteurJoueur;
use App\Entity\User;
use App\Enum\TypeCompteur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompteurJoueur>
 */
class CompteurJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompteurJoueur::class);
    }

    /**
     * Incrémente un compteur et renvoie sa valeur après coup.
     *
     * Écrit en SQL natif avec `ON DUPLICATE KEY UPDATE` plutôt qu'en lisant l'entité
     * pour l'additionner : un compteur est exactement le cas où un read-modify-write
     * perd des incréments. Deux monstres tués dans la même seconde par deux requêtes
     * concurrentes liraient la même valeur de départ et n'en compteraient qu'un.
     * L'index unique (user, type, cible) est ce qui rend l'upsert possible.
     *
     * L'écriture a lieu immédiatement, dans la transaction de l'appelant : le service
     * ne flushe pas, mais il n'a rien à flusher.
     */
    public function incrementer(User $user, TypeCompteur $type, int $cibleId, int $pas): int
    {
        $connection = $this->getEntityManager()->getConnection();

        $connection->executeStatement(
            'INSERT INTO joueur_compteur (user_id, type, cible_id, valeur, maj_at)
             VALUES (:user, :type, :cible, :pas, :maintenant)
             ON DUPLICATE KEY UPDATE valeur = valeur + :pas, maj_at = :maintenant',
            [
                'user' => $user->getId(),
                'type' => $type->value,
                'cible' => $cibleId,
                'pas' => $pas,
                'maintenant' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        return $this->valeur($user, $type, $cibleId);
    }

    /** Valeur d'un compteur (0 s'il n'a jamais été incrémenté). */
    public function valeur(User $user, TypeCompteur $type, int $cibleId): int
    {
        $valeur = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT valeur FROM joueur_compteur WHERE user_id = :user AND type = :type AND cible_id = :cible',
            ['user' => $user->getId(), 'type' => $type->value, 'cible' => $cibleId]
        );

        return $valeur === false ? 0 : (int)$valeur;
    }

    /**
     * Tous les compteurs d'un joueur pour un type, indexés par cible.
     *
     * @return array<int, int> cibleId => valeur
     */
    public function valeursParCible(User $user, TypeCompteur $type): array
    {
        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT cible_id, valeur FROM joueur_compteur WHERE user_id = :user AND type = :type',
            ['user' => $user->getId(), 'type' => $type->value]
        );

        $valeurs = [];
        foreach ($lignes as $ligne) {
            $valeurs[(int)$ligne['cible_id']] = (int)$ligne['valeur'];
        }

        return $valeurs;
    }
}
