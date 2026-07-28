<?php

namespace App\DTO\Artisanat;

use Symfony\Component\Validator\Constraints as Assert;

class MetierSaveDTO
{
    public function __construct(
        public readonly int $id = 0,
        #[Assert\NotBlank]
        public readonly string $nom = '',
        public readonly ?string $description = null,
        public readonly ?string $icone = null,
        #[Assert\NotBlank]
        public readonly string $famille = 'recolte',
        public readonly int $niveauMax = 200,
        /** @var int[] ids des PNJ maîtres — RESYNCHRONISÉ, les absents sont détachés. */
        public readonly array $maitres = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'icone' => $this->icone,
            'famille' => $this->famille,
            'niveauMax' => $this->niveauMax,
            'maitres' => $this->maitres,
        ];
    }
}
