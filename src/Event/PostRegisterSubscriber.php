<?php


namespace App\Event;


use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Inventaire;
use App\Entity\JoueurCaracteristique;
use App\Entity\JoueurCaracteristiqueBonus;
use App\Entity\NiveauJoueur;
use App\Entity\User;
use App\Repository\CaracteristiqueRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\ClasseRepository;
use App\Repository\NiveauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Security;

class PostRegisterSubscriber implements EventSubscriberInterface
{

    private $security;
    private $entityManager;
    private $niveauRepository;
    private $classeRepository;
    private $carteCarreauRepository;
    private $carteRepository;
    private $caracteristiqueRepository;

    public function __construct(
        Security $security,
        EntityManagerInterface $entityManager,
        NiveauRepository $niveauRepository,
        ClasseRepository $classeRepository,
        CarteCarreauRepository $carteCarreauRepository,
        CarteRepository $carteRepository,
        CaracteristiqueRepository $caracteristiqueRepository
    )
    {
        $this->security = $security;
        $this->entityManager = $entityManager;
        $this->niveauRepository = $niveauRepository;
        $this->classeRepository = $classeRepository;
        $this->carteCarreauRepository = $carteCarreauRepository;
        $this->carteRepository = $carteRepository;
        $this->caracteristiqueRepository = $caracteristiqueRepository;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['initializeJoinTableForUser', EventPriorities::POST_WRITE]
        ];
    }

    public function initializeJoinTableForUser(ViewEvent $event){
        $user = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if ($user instanceof User && $method === "POST") {
            $niveauJoueur = new NiveauJoueur();
            $niveauJoueur->setExperience(0);
            $niveauJoueur->setUser($user);
            $niveau = $this->niveauRepository->findOneBy(['niveau' => 1]);
            $niveauJoueur->setNiveau($niveau);
            $this->entityManager->persist($niveauJoueur);
            $this->entityManager->flush();
            $classe = $this->classeRepository->findOneBy(['id' => 3]);
            $user->setClasse($classe);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $inventaire = new Inventaire();
            $inventaire->setTailleMax(100);
            $inventaire->setUser($user);
            $this->entityManager->persist($inventaire);
            $this->entityManager->flush();

            for($indexCaracteristique = 1; $indexCaracteristique <= 6; $indexCaracteristique++){
                $joueurCaracteristique = new JoueurCaracteristique();
                $joueurCaracteristiqueBonus = new JoueurCaracteristiqueBonus();
                $caracteristique = $this->caracteristiqueRepository->findOneBy(['id' => $indexCaracteristique]);

                /** Initialisation des caractéristiques */
                $joueurCaracteristique->setUser($user);
                $joueurCaracteristique->setCaracteristique($caracteristique);
                $joueurCaracteristique->setPoints(1);
                $this->entityManager->persist($joueurCaracteristique);
                $this->entityManager->flush();

                /** Initialisation des caractéritiques d'équipements */
                $joueurCaracteristiqueBonus->setJoueur($user);
                $joueurCaracteristiqueBonus->setCaracteristique($caracteristique);
                $joueurCaracteristiqueBonus->setPoints(0);
                $this->entityManager->persist($joueurCaracteristiqueBonus);
                $this->entityManager->flush();
            }


            $firstMap = $this->carteRepository->findOneBy(['id' => 1]);
            $this->carteCarreauRepository->setPlayerOnCaseInAMap($firstMap->getId(), 10, 10, $user->getId());
        }
    }

}