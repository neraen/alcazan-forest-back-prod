<?php

namespace App\service;

use App\Enum\CategorieEvenement;
use App\Enum\TypeCible;
use App\Enum\TypeEvenement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transforme les lignes brutes de `evenement_jeu` en événements lisibles.
 *
 * Il résout deux choses que la base ne peut pas joindre elle-même : les pseudos des deux
 * joueurs, et le nom de la cible — dont `cible_id` est un entier NU sans clé étrangère
 * (voir `TypeCible`). Les résolutions sont GROUPÉES : une requête pour tous les joueurs
 * d'une page, puis une par type de cible présent. Résoudre ligne à ligne ferait cinquante
 * requêtes pour une page de cinquante événements.
 *
 * ⚠️ Ne pas confondre avec le figeage fait à l'ÉCRITURE (`JournalService::figerItems()`).
 * Les items d'un échange ou d'une vente ont leur nom gravé dans `contexte.items` au moment
 * du fait, et ce nom-là survit à la suppression du contenu ; ce que le normalizer résout
 * ici, c'est la cible principale, dont un contenu supprimé donnera « Monstre inconnu (#12) ».
 */
class JournalNormalizer
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Où lire le nom d'une cible. Piloté par l'enum, donc jamais par une chaîne venue
     * d'une requête : aucun risque d'injection malgré l'interpolation du nom de table.
     *
     * @return array{0: string, 1: string} table, colonne
     */
    private static function source(TypeCible $type): array
    {
        return match ($type) {
            TypeCible::EQUIPEMENT => ['equipement', 'nom'],
            TypeCible::CONSOMMABLE => ['consommable', 'nom'],
            TypeCible::OBJET => ['objet', 'name'],
            TypeCible::MONSTRE => ['monstre', 'name'],
            TypeCible::BOSS => ['boss', 'name'],
            TypeCible::RECETTE => ['recette', 'nom'],
            TypeCible::QUETE => ['quete', 'name'],
            TypeCible::GUILDE => ['guilde', 'nom'],
            TypeCible::PNJ => ['pnj', 'name'],
            TypeCible::INTERACTION => ['interaction', 'nom'],
        };
    }

    /**
     * @param list<array> $lignes lignes brutes du repository
     * @return list<array> événements normalisés, dans le même ordre
     */
    public function normaliserPlusieurs(array $lignes): array
    {
        if ($lignes === []) {
            return [];
        }

        $pseudos = $this->chargerPseudos($lignes);
        $noms = $this->chargerNomsDeCible($lignes);

        $evenements = [];
        foreach ($lignes as $ligne) {
            $type = TypeEvenement::from((string) $ligne['type']);
            $cibleType = $ligne['cible_type'] === null ? null : TypeCible::tryFrom((string) $ligne['cible_type']);
            $cibleId = $ligne['cible_id'] === null ? null : (int) $ligne['cible_id'];

            $evenement = [
                'id' => (int) $ligne['id'],
                'type' => $type->value,
                'typeLabel' => $type->label(),
                'categorie' => $type->categorie()->value,
                'categorieLabel' => $type->categorie()->label(),
                'acteur' => $this->joueur($ligne['acteur_id'], $pseudos),
                'cibleUser' => $this->joueur($ligne['cible_user_id'], $pseudos),
                'cible' => $cibleType === null || $cibleId === null ? null : [
                    'type' => $cibleType->value,
                    'typeLabel' => $cibleType->label(),
                    'id' => $cibleId,
                    'nom' => $noms[$cibleType->value][$cibleId]
                        ?? sprintf('%s inconnu (#%d)', $cibleType->label(), $cibleId),
                ],
                'quantite' => (int) $ligne['quantite'],
                'montantOr' => (int) $ligne['montant_or'],
                'contexte' => $this->decoderContexte($ligne['contexte']),
                'creeLe' => (string) $ligne['cree_le'],
            ];

            $evenement['phrase'] = $type->phrase($evenement);
            $evenements[] = $evenement;
        }

        return $evenements;
    }

    /** Le référentiel servi au front, pour qu'il ne connaisse aucun type en dur. */
    public function referentiels(): array
    {
        return [
            'types' => array_map(
                static fn (TypeEvenement $type) => [
                    'valeur' => $type->value,
                    'label' => $type->label(),
                    'categorie' => $type->categorie()->value,
                ],
                TypeEvenement::cases()
            ),
            'categories' => array_map(
                static fn (CategorieEvenement $categorie) => [
                    'valeur' => $categorie->value,
                    'label' => $categorie->label(),
                ],
                CategorieEvenement::cases()
            ),
        ];
    }

    /** @return array<int, string> id => pseudo */
    private function chargerPseudos(array $lignes): array
    {
        $ids = [];
        foreach ($lignes as $ligne) {
            foreach (['acteur_id', 'cible_user_id'] as $colonne) {
                if ($ligne[$colonne] !== null) {
                    $ids[(int) $ligne[$colonne]] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $resultats = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, pseudo FROM user WHERE id IN (' . implode(',', array_map('intval', array_keys($ids))) . ')'
        );

        $pseudos = [];
        foreach ($resultats as $resultat) {
            $pseudos[(int) $resultat['id']] = (string) $resultat['pseudo'];
        }

        return $pseudos;
    }

    /** @return array<string, array<int, string>> typeCible => (id => nom) */
    private function chargerNomsDeCible(array $lignes): array
    {
        $parType = [];
        foreach ($lignes as $ligne) {
            if ($ligne['cible_type'] === null || $ligne['cible_id'] === null) {
                continue;
            }
            $type = TypeCible::tryFrom((string) $ligne['cible_type']);
            if ($type !== null) {
                $parType[$type->value][(int) $ligne['cible_id']] = true;
            }
        }

        $noms = [];
        foreach ($parType as $valeur => $ids) {
            [$table, $colonne] = self::source(TypeCible::from($valeur));

            $resultats = $this->entityManager->getConnection()->fetchAllAssociative(
                sprintf(
                    'SELECT id, %s AS nom FROM %s WHERE id IN (%s)',
                    $colonne,
                    $table,
                    implode(',', array_map('intval', array_keys($ids)))
                )
            );

            foreach ($resultats as $resultat) {
                $noms[$valeur][(int) $resultat['id']] = (string) $resultat['nom'];
            }
        }

        return $noms;
    }

    /** @param array<int, string> $pseudos */
    private function joueur(mixed $id, array $pseudos): ?array
    {
        if ($id === null) {
            return null;
        }

        $id = (int) $id;

        return ['id' => $id, 'pseudo' => $pseudos[$id] ?? sprintf('Joueur #%d', $id)];
    }

    private function decoderContexte(mixed $contexte): array
    {
        if (!is_string($contexte) || $contexte === '') {
            return [];
        }

        try {
            $decode = json_decode($contexte, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decode) ? $decode : [];
    }
}
