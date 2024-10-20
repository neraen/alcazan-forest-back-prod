<?php

namespace App\Event;

use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

class NextQuestSequenceEvent extends Event
{
    public function __construct(private readonly User $user, private int $sequenceId)
    {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSequenceId(): int
    {
        return $this->sequenceId;
    }




}