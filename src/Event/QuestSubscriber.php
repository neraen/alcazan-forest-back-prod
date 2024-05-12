<?php

namespace App\Event;



use App\service\QuestService;

class QuestSubscriber implements \Symfony\Component\EventDispatcher\EventSubscriberInterface
{
    public function __construct(
        private readonly QuestService $questService
    )
    {
    }

    public function onNextQuestSequenceEvent(NextQuestSequenceEvent $event){
        $this->questService->setNextSequence($event->getUserId(), $event->getSequenceId());
        $this->questService->giveRecompenseToUser($event->getUserId(), $event->getSequenceId());
    }
    public static function getSubscribedEvents()
    {
        return [
            NextQuestSequenceEvent::class => 'onNextQuestSequenceEvent'
        ];
    }
}