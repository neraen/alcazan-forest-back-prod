<?php

namespace App\service;

use App\Entity\HotelVente;
use App\Entity\User;

/**
 * Format UNIQUE d'une annonce de l'hôtel des ventes, servi tel quel par l'API : le front
 * remplace son état local par ce payload, sans deltas. Ne jamais exposer les entités.
 *
 * ⚠️ Contrairement à EchangeNormalizer, on ne construit AUCUN chemin d'image : le payload
 * porte le nom de fichier brut plus la position, et `itemUtils.itemImage()` fait le chemin
 * côté client. C'est la convention en vigueur depuis l'upload d'images de l'administration
 * (doc §17) — un chemin construit ici serait un second endroit à corriger le jour où
 * l'arborescence de `public/img` bouge.
 */
class HotelVenteNormalizer
{
    public function __construct(
        private readonly SacService $sacService
    ) {}

    /**
     * @param HotelVente[] $annonces
     */
    public function normalizeListe(array $annonces, ?User $lecteur = null): array
    {
        return array_map(fn (HotelVente $annonce) => $this->normalize($annonce, $lecteur), $annonces);
    }

    public function normalize(HotelVente $annonce, ?User $lecteur = null): array
    {
        $vendeur = $annonce->getVendeur();

        return [
            'id' => $annonce->getId(),
            'vendeur' => [
                'id' => $vendeur->getId(),
                'pseudo' => $vendeur->getPseudo(),
            ],
            // Calculé ici plutôt que déduit côté client : le front n'a pas toujours son propre
            // id sous la main, et c'est ce booléen qui grise le bouton « Acheter ».
            'estMien' => $lecteur !== null && $vendeur->getId() === $lecteur->getId(),
            'item' => $this->normalizeItem($annonce),
            'quantite' => $annonce->getQuantite(),
            'prix' => $annonce->getPrix(),
            'prixUnitaire' => $annonce->getPrixUnitaire(),
            'fraisDepot' => $annonce->getFraisDepot(),
            'statut' => $annonce->getStatut()->value,
            'statutLabel' => $annonce->getStatut()->label(),
            'createdAt' => $annonce->getCreatedAt()->format(DATE_ATOM),
            'expiresAt' => $annonce->getExpiresAt()->format(DATE_ATOM),
            'closedAt' => $annonce->getClosedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * `item_id` n'a pas de clé étrangère : un item supprimé du contenu laisse une annonce
     * orpheline. Elle doit rester lisible — son vendeur peut encore la retirer, et l'objet
     * séquestré lui reviendra même si le modèle n'existe plus.
     */
    private function normalizeItem(HotelVente $annonce): array
    {
        $type = $annonce->getType();

        try {
            $fiche = $this->sacService->decrireItem($type, $annonce->getItemId());
        } catch (\DomainException) {
            $fiche = ['nom' => 'Objet inconnu', 'icone' => null, 'description' => null,
                      'position' => null, 'rarete' => null, 'prixRevente' => 0];
        }

        return [
            'type' => $type->value,
            'id' => $annonce->getItemId(),
            'nom' => $fiche['nom'],
            // `image` et non `icone` : c'est la clé qu'attend itemImage({type, image, position}).
            'image' => $fiche['icone'],
            'position' => $fiche['position'],
            'rarete' => $fiche['rarete'],
            'description' => $fiche['description'],
            // Prix de rachat par le marchand PNJ : le seul repère de valeur dont dispose un
            // joueur pour juger si une annonce est une affaire.
            'prixRevente' => $fiche['prixRevente'],
        ];
    }
}
