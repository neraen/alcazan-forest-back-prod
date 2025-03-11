<?php

namespace App\DTO\Action;

use Symfony\Component\Validator\Constraints as Assert;

class ActionDonnerObjetDTO
{
    #[Assert\NotBlank("Le champ sequenceId est obligatoire.")]
    public $sequenceId;

    #[Assert\NotBlank("Le champ actionId est obligatoire.")]
    public $actionId;


    public function getSequenceId(): ?int
    {
        return $this->sequenceId;
    }

    public function setSequenceId(int $sequenceId): self
    {
        $this->sequenceId = $sequenceId;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getActionId()
    {
        return $this->actionId;
    }

    /**
     * @param mixed $actionId
     */
    public function setActionId($actionId): void
    {
        $this->actionId = $actionId;
    }


}