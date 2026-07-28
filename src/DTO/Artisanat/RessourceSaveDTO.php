<?php

namespace App\DTO\Artisanat;

use Symfony\Component\Validator\Constraints as Assert;

class RessourceSaveDTO
{
    public function __construct(
        public readonly int $id = 0,
        #[Assert\NotBlank]
        public readonly string $nom = '',
        public readonly ?string $description = null,
        public readonly ?string $image = null,
        public readonly ?int $prixVente = null,
        /** Nul = l'objet n'est (plus) une ressource de métier. */
        public readonly ?int $metierId = null,
        public readonly int $niveauRessource = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'image' => $this->image,
            'prixVente' => $this->prixVente,
            'metierId' => $this->metierId,
            'niveauRessource' => $this->niveauRessource,
        ];
    }
}
