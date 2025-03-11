<?php

namespace App\DTO\Action;

use Symfony\Component\Validator\Constraints as Assert;

class ActionPasserDialogueDTO
{
    #[Assert\NotBlank("Le champ sequenceId est obligatoire.")]
    public $sequenceId;

    public function getSequenceId(): ?int
    {
        return $this->sequenceId;
    }

    public function setSequenceId(int $sequenceId): self
    {
        $this->sequenceId = $sequenceId;

        return $this;
    }
}