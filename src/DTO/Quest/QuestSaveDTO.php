<?php

namespace App\DTO\Quest;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload de sauvegarde du QuestMaker. Les séquences (et leurs actions /
 * récompenses) restent des tableaux bruts : la validation métier fine est
 * faite par QuestEditorService, qui connaît les règles par type d'action.
 */
class QuestSaveDTO
{
    public function __construct(
        public readonly ?int $id = null,
        #[Assert\NotBlank(message: "Le nom de la quête est obligatoire.")]
        public readonly ?string $name = null,
        public readonly ?int $minimalLevel = null,
        public readonly ?int $alignementId = null,
        public readonly ?int $objetId = null,
        public readonly ?int $prerequisiteQueteId = null,
        #[Assert\Type('array')]
        public readonly array $sequences = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id ?? 0,
            'name' => $this->name,
            'minimalLevel' => $this->minimalLevel ?? 0,
            'alignementId' => $this->alignementId ?? 0,
            'objetId' => $this->objetId ?? 0,
            'prerequisiteQueteId' => $this->prerequisiteQueteId ?? 0,
            'sequences' => $this->sequences,
        ];
    }
}
