<?php

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

class NextQuestSequenceEvent extends Event
{
    public function __construct(private int $userId, private int $sequenceId)
    {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getSequenceId(): int
    {
        return $this->sequenceId;
    }




}