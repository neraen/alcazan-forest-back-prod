<?php

namespace App\Repository;

use App\Entity\Equipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Equipement|null find($id, $lockMode = null, $lockVersion = null)
 * @method Equipement|null findOneBy(array $criteria, array $orderBy = null)
 * @method Equipement[]    findAll()
 * @method Equipement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipement::class);
    }

    /**
     * Ids des équipements dont le nom contient `$terme`. Voir ObjetRepository::findIdsParNom
     * pour le pourquoi (recherche de l'hôtel des ventes, `item_id` sans clé étrangère).
     *
     * @return int[]
     */
    public function findIdsParNom(string $terme): array
    {
        $lignes = $this->createQueryBuilder('equipement')
            ->select('equipement.id')
            ->where('equipement.nom LIKE :terme')
            ->setParameter('terme', '%' . $terme . '%')
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($lignes, 'id'));
    }

    /**
     * ⚠️ Ne PAS joindre `equipement.classe` ici : la relation est N-N, un équipement à deux
     * classes reviendrait en double dans ce résultat scalaire. Les classes se récupèrent à
     * part, via getClassesByEquipement().
     */
    public function getAllEquipementGroupedByPosition(){
        return $this->createQueryBuilder('equipement')
            ->select('equipement.nom, equipement.id, equipement.icone, equipement.prixRevente, equipement.description,
                equipement.prixAchat, equipement.level_min levelMin, positionEquipement.id positionEquipementId,
                positionEquipement.name positionEquipementName,
                rarity.id rarityId, rarity.name rarityName')
            ->leftJoin('equipement.positionEquipement', 'positionEquipement')
            ->leftJoin('equipement.rarity', 'rarity')
            ->getQuery()
            ->getResult();
    }

    /**
     * Classes autorisées, indexées par id d'équipement. Un équipement sans aucune classe
     * (absent du tableau) est utilisable par TOUTES les classes — c'est la convention retenue,
     * elle reste juste quand une nouvelle classe est ajoutée au jeu.
     *
     * @return array<int, array<int, array{id: int, nom: string}>>
     */
    public function getClassesByEquipement(): array
    {
        $lignes = $this->createQueryBuilder('equipement')
            ->select('equipement.id as equipementId, classe.id, classe.nom')
            ->join('equipement.classe', 'classe')
            ->getQuery()
            ->getResult();

        $parEquipement = [];
        foreach ($lignes as $ligne) {
            $parEquipement[$ligne['equipementId']][] = ['id' => $ligne['id'], 'nom' => $ligne['nom']];
        }

        return $parEquipement;
    }

    // /**
    //  * @return Equipement[] Returns an array of Equipement objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Equipement
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
