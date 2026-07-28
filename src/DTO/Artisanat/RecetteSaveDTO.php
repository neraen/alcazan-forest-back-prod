<?php

namespace App\DTO\Artisanat;

class RecetteSaveDTO
{
    public function __construct(
        /** Fiche : id, nom, description, metierId, niveauRequis, difficulte, tempsSecondes, experienceMetier, actif */
        public readonly array $recette = [],
        /** Sortie : objetId | equipementId | consommableId + quantity */
        public readonly array $produit = [],
        /** Ingrédients avec ids STABLES : sans id = création, absent = suppression. */
        public readonly array $ingredients = [],
    ) {}

    public function toArray(): array
    {
        return [
            'recette' => $this->recette,
            'produit' => $this->produit,
            'ingredients' => $this->ingredients,
        ];
    }
}
