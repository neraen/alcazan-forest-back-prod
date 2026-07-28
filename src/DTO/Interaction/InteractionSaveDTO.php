<?php

namespace App\DTO\Interaction;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sauvegarde complète d'une interaction : fiche + récompense + conditions.
 * La validation métier vit dans InteractionEditorService ; ici on ne borne que la forme.
 */
class InteractionSaveDTO
{
    public function __construct(
        public readonly ?int $id = null,
        #[Assert\NotBlank(message: "L'interaction doit avoir un nom.")]
        public readonly string $nom = '',
        public readonly string $type = 'actionner',
        public readonly ?string $skin = null,
        public readonly ?string $messageSucces = null,
        public readonly int $coutPa = 0,
        public readonly ?string $effect = null,
        public readonly mixed $effectParams = null,
        public readonly ?int $metierId = null,
        public readonly int $niveauMetierMin = 0,
        public readonly int $experienceMetier = 0,
        public readonly int $cooldownSecondes = 0,
        public readonly string $porteeRecharge = 'joueur',
        public readonly bool $usageUnique = false,
        /** Propose au joueur le choix « récolte mesurée / récolte intensive ». */
        public readonly bool $recolteChoix = false,
        public readonly bool $actif = true,
        public readonly array $recompense = [],
        public readonly array $conditions = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'type' => $this->type,
            'skin' => $this->skin,
            'messageSucces' => $this->messageSucces,
            'coutPa' => $this->coutPa,
            'effect' => $this->effect,
            'effectParams' => $this->effectParams,
            'metierId' => $this->metierId,
            'niveauMetierMin' => $this->niveauMetierMin,
            'experienceMetier' => $this->experienceMetier,
            'cooldownSecondes' => $this->cooldownSecondes,
            'porteeRecharge' => $this->porteeRecharge,
            'usageUnique' => $this->usageUnique,
            'recolteChoix' => $this->recolteChoix,
            'actif' => $this->actif,
            'recompense' => $this->recompense,
            'conditions' => $this->conditions,
        ];
    }
}
