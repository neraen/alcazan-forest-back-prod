<?php


namespace App\Event;


use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Niveau;
use App\Entity\NiveauJoueur;
use App\Entity\User;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\ClasseRepository;
use App\Repository\NiveauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpKernel\KernelEvents;

class RegisterSubscriber implements EventSubscriberInterface
{

    private $security;
    private $entityManager;
    private $niveauRepository;
    private $classeRepository;
    private $carteRepository;
    private $carteCarreauRepository;

    public function __construct(
        Security $security,
        EntityManagerInterface $entityManager,
        NiveauRepository $niveauRepository,
        ClasseRepository $classeRepository,
        CarteRepository $carteRepository,
        CarteCarreauRepository $carteCarreauRepository
    )
    {
        $this->security = $security;
        $this->entityManager = $entityManager;
        $this->niveauRepository = $niveauRepository;
        $this->classeRepository = $classeRepository;
        $this->carteRepository = $carteRepository;
        $this->carteCarreauRepository = $carteCarreauRepository;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['initializeUser', EventPriorities::PRE_VALIDATE],
            KernelEvents::RESPONSE => ['onKernelResponse', EventPriorities::PRE_RESPOND]
        ];
    }

    public function onKernelResponse(ResponseEvent $responseEvent){
        $response = $responseEvent->getResponse();
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT');
        $response->setStatusCode(Response::HTTP_OK);
    }

    public function initializeUser(ViewEvent $event)
    {
        $user = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if ($user instanceof User && $method === "POST") {
            $user->setCreated(new \DateTime());
            $user->setIsActive(1);
            $user->setCurrentLife(400);
            $user->setMaxLife(400);
            $user->setMaxMana(100);
            $user->setCurrentMana(100);
            $user->setMouvementPoint(800);
            $user->setActionPoint(600);
            $user->setMoney(10);
            $user->setMaxPointCarac(0);
            $user->setActualPointCarac(0);
            $user->setRestePointCarac(0);
            $user->setTutorialActive(true);

            $firstMap = $this->carteRepository->findOneBy(['id' => 2]);
            $user->setMap($firstMap);
            $user->setCaseAbscisse(9);
            $user->setCaseOrdonnee(9);


//            $niveauJoueurEntity = new NiveauJoueur();
//            $niveauEntity = $this->niveauRepository->findOneBy(['niveau' => 1]);
//
//            $niveauJoueurEntity->setNiveau($niveauEntity);
//            $niveauJoueurEntity->setUser($user);
//            $niveauJoueurEntity->setExperience(0);

        }
    }
}