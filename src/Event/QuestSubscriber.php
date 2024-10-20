<?php

namespace App\Event;

use App\Repository\SequenceRepository;
use App\service\QuestService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class QuestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly QuestService $questService,
        private readonly SequenceRepository $sequenceRepository
    ){}

    public function onNextQuestSequenceEvent(NextQuestSequenceEvent $event): void {
        $this->questService->giveRecompenseToUser($event->getUser(), $event->getSequenceId());
        $sequence = $this->sequenceRepository->find($event->getSequenceId());
        if($sequence->getIsLast()){
            $this->questService->setQuestDone($event->getUser(), $sequence);
        }else{
            $this->questService->setNextSequence($event->getUser()->getId(), $sequence->getId());
        }
    }
    public static function getSubscribedEvents()
    {
        return [
            NextQuestSequenceEvent::class => 'onNextQuestSequenceEvent'
        ];
    }
}