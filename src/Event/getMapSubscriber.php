<?php


namespace App\Event;


use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\User;
use App\Repository\CarteCarreauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpKernel\KernelEvents;

class getMapSubscriber implements EventSubscriberInterface
{

    private $security;
    private $entityManager;
    private $carreauRepository;


    public function __construct(Security $security, EntityManagerInterface $entityManager, CarteCarreauRepository $carreauRepository)
    {
        $this->security = $security;
        $this->entityManager = $entityManager;
        $this->carreauRepository = $carreauRepository;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['getUsers', EventPriorities::PRE_WRITE]
        ];
    }

    public function getUsers(ViewEvent $event)
    {
        $user = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if($user instanceof User && $method === "PATCH"){
            $newCase = $this->carreauRepository->findByCoordonnee($user->getCaseAbscisse(), $user->getCaseOrdonnee());
            if($newCase[0]->getJoueur() === null){

                $this->carreauRepository->updatePlayerInCase($user);
                $newCase[0]->setJoueur($user);
                $this->entityManager->persist($newCase[0]);
                $this->entityManager->flush();
            }
        }
    }
}