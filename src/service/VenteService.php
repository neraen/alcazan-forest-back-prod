<?php

namespace App\service;

use App\Entity\User;
use App\Enum\TypeItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vente d'un item du sac au marchand. Le prix est celui porté par l'item
 * (`prixRevente` / `prix_vente`), **0 s'il n'est pas renseigné**.
 *
 * Toutes les mutations (retrait de la pile, crédit de l'or) passent par SacService dans UNE
 * transaction — un item ne peut donc jamais disparaître sans être payé, ni être payé sans
 * disparaître. Un item réservé (échange en cours) n'est pas vendable : le disponible est
 * contrôlé par SacService::retirerItem.
 *
 * Un objet équipé vit dans `user_equipement`, pas dans le sac : il n'est pas vendable tant
 * qu'il n'a pas été retiré.
 */
class VenteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SacService             $sacService
    ) {}

    /**
     * @param int $quantite nombre d'exemplaires à céder (≥ 1, plafonné par la pile disponible)
     *
     * @return array{prix: int, prixUnitaire: int, quantite: int, nom: string, money: int}
     *         prix total encaissé, prix unitaire, quantité vendue, nom de l'item, or restant
     * @throws \DomainException item absent du sac, quantité invalide ou disponible insuffisant
     */
    public function sell(User $user, TypeItem $type, int $itemId, int $quantite = 1): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $type, $itemId, $quantite): array {
            if ($quantite < 1) {
                throw new \DomainException("Quantité invalide.");
            }

            // Le stock est relu dans la transaction (client périmé : objet consommé, vendu dans
            // un autre onglet, ou réservé par un échange entre-temps).
            $this->sacService->retirerItem($user, $type, $itemId, $quantite);

            $description = $this->sacService->decrireItem($type, $itemId);

            $prixUnitaire = $description['prixRevente'];
            $prix = $prixUnitaire * $quantite;
            $this->sacService->crediterOr($user, $prix);

            return [
                'prix' => $prix,
                'prixUnitaire' => $prixUnitaire,
                'quantite' => $quantite,
                'nom' => $description['nom'],
                'money' => $user->getMoney(),
            ];
        });
    }
}
