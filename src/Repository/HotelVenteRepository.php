<?php

namespace App\Repository;

use App\Entity\HotelVente;
use App\Entity\User;
use App\Enum\StatutHotelVente;
use App\Enum\TriHotelVente;
use App\Enum\TypeItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method HotelVente|null find($id, $lockMode = null, $lockVersion = null)
 * @method HotelVente|null findOneBy(array $criteria, array $orderBy = null)
 * @method HotelVente[]    findAll()
 * @method HotelVente[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HotelVenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HotelVente::class);
    }

    /**
     * Une page du catalogue, plus le total pour la pagination.
     *
     * Contrairement au catalogue de recettes (corpus fermé, filtré côté client), l'hôtel est
     * alimenté par les joueurs : il n'a pas de borne, tout filtrer et trier ici est le seul
     * moyen de ne pas envoyer la table entière au navigateur.
     *
     * @param array<string, int[]>|null $idsParType résultat de SacService::rechercherItemsParNom,
     *                                              null quand aucune recherche n'est demandée
     * @return array{annonces: HotelVente[], total: int}
     */
    public function catalogue(
        ?TypeItem $type,
        ?array $idsParType,
        TriHotelVente $tri,
        int $page,
        int $parPage
    ): array {
        $total = (int) $this->baseCatalogue($type, $idsParType)
            ->select('COUNT(vente.id)')
            ->getQuery()
            ->getSingleScalarResult();

        [$champ, $sens] = $tri->ordre();

        $annonces = $this->baseCatalogue($type, $idsParType)
            ->orderBy('vente.' . $champ, $sens)
            // Départage stable : deux annonces au même prix doivent toujours sortir dans le
            // même ordre, sinon la même ligne peut apparaître sur deux pages successives.
            ->addOrderBy('vente.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return ['annonces' => $annonces, 'total' => $total];
    }

    /**
     * Filtres communs au comptage et à la page : annonces en vente et non périmées.
     *
     * L'expiration est testée ICI en plus du statut parce qu'elle est PARESSEUSE — une
     * annonce dépassée peut rester `en_vente` en base jusqu'au passage de la commande. Sans
     * ce filtre, le catalogue proposerait des lots que l'achat refuserait aussitôt.
     */
    private function baseCatalogue(?TypeItem $type, ?array $idsParType): QueryBuilder
    {
        $requete = $this->createQueryBuilder('vente')
            ->where('vente.statut = :statut')
            ->andWhere('vente.expiresAt > :maintenant')
            ->setParameter('statut', StatutHotelVente::EN_VENTE)
            ->setParameter('maintenant', new \DateTimeImmutable());

        if ($type !== null) {
            $requete->andWhere('vente.type = :type')->setParameter('type', $type);
        }

        if ($idsParType !== null) {
            $this->appliquerRecherche($requete, $type, $idsParType);
        }

        return $requete;
    }

    /**
     * Restreint aux couples (type, item_id) dont le nom correspond à la recherche.
     *
     * Un OR par famille plutôt qu'un `IN` global : `item_id` seul ne veut rien dire, l'objet 12
     * et l'équipement 12 sont deux choses différentes. Une recherche sans aucune correspondance
     * doit rendre zéro annonce, d'où la condition impossible plutôt qu'un filtre absent.
     */
    private function appliquerRecherche(QueryBuilder $requete, ?TypeItem $type, array $idsParType): void
    {
        $clauses = [];
        foreach ($idsParType as $valeurType => $ids) {
            if ($ids === [] || ($type !== null && $type->value !== $valeurType)) {
                continue;
            }
            $parametre = 'ids_' . $valeurType;
            $clauses[] = sprintf('(vente.type = :type_%s AND vente.itemId IN (:%s))', $valeurType, $parametre);
            $requete->setParameter('type_' . $valeurType, TypeItem::from($valeurType));
            $requete->setParameter($parametre, $ids);
        }

        if ($clauses === []) {
            $requete->andWhere('1 = 0');

            return;
        }

        $requete->andWhere('(' . implode(' OR ', $clauses) . ')');
    }

    /** Annonces encore en vente d'un joueur, les plus récentes d'abord. */
    public function findActivesDe(User $user): array
    {
        return $this->createQueryBuilder('vente')
            ->where('vente.vendeur = :user')
            ->andWhere('vente.statut = :statut')
            ->setParameter('user', $user)
            ->setParameter('statut', StatutHotelVente::EN_VENTE)
            ->orderBy('vente.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Combien de lots ce joueur a-t-il en vente (plafond ANNONCES_MAX_PAR_JOUEUR). */
    public function compterActivesDe(User $user): int
    {
        return (int) $this->createQueryBuilder('vente')
            ->select('COUNT(vente.id)')
            ->where('vente.vendeur = :user')
            ->andWhere('vente.statut = :statut')
            ->setParameter('user', $user)
            ->setParameter('statut', StatutHotelVente::EN_VENTE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Dernières annonces closes d'un joueur : ce qui s'est vendu, expiré ou été retiré. */
    public function findHistoriqueDe(User $user, int $limite): array
    {
        return $this->createQueryBuilder('vente')
            ->where('vente.vendeur = :user')
            ->andWhere('vente.statut != :statut')
            ->setParameter('user', $user)
            ->setParameter('statut', StatutHotelVente::EN_VENTE)
            ->orderBy('vente.closedAt', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** @return HotelVente[] annonces encore en vente dont l'expiration est dépassée */
    public function findPerimees(): array
    {
        return $this->createQueryBuilder('vente')
            ->where('vente.statut = :statut')
            ->andWhere('vente.expiresAt <= :maintenant')
            ->setParameter('statut', StatutHotelVente::EN_VENTE)
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
