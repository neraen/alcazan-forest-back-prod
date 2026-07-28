<?php

namespace App\DTO\DonjonEditor;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sauvegarde complète d'un donjon : fiche + plan des salles + mécaniques.
 * La validation métier (bornes, unicité des cartes, params) vit dans
 * DonjonEditorService — ici on ne fait que borner la forme du payload.
 */
class DonjonSaveDTO
{
    public function __construct(
        public readonly ?int $id = null,
        #[Assert\NotBlank(message: "Le donjon doit avoir un nom.")]
        public readonly string $nom = '',
        public readonly ?string $description = null,
        public readonly ?string $icone = null,
        public readonly int $niveauMin = 0,
        #[Assert\Positive(message: "La taille de groupe doit valoir au moins 1.")]
        public readonly int $tailleGroupeMax = 5,
        public readonly int $dureeMaxMinutes = 180,
        #[Assert\Range(min: 0, max: 23, notInRangeMessage: "L'heure de reset doit être comprise entre 0 et 23.")]
        public readonly int $heureReset = 5,
        public readonly bool $actif = true,
        public readonly ?int $carteSortieId = null,
        public readonly int $sortieAbscisse = 0,
        public readonly int $sortieOrdonnee = 0,
        public readonly array $salles = [],
        public readonly array $mecaniques = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'icone' => $this->icone,
            'niveauMin' => $this->niveauMin,
            'tailleGroupeMax' => $this->tailleGroupeMax,
            'dureeMaxMinutes' => $this->dureeMaxMinutes,
            'heureReset' => $this->heureReset,
            'actif' => $this->actif,
            'carteSortieId' => $this->carteSortieId,
            'sortieAbscisse' => $this->sortieAbscisse,
            'sortieOrdonnee' => $this->sortieOrdonnee,
            'salles' => $this->salles,
            'mecaniques' => $this->mecaniques,
        ];
    }
}
