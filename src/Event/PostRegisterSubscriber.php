<?php


namespace App\Event;

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
use App\Config\GameContent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManagerInterface;

#[AsDoctrineListener(event: Events::postPersist)]
class PostRegisterSubscriber
{
    private $entityManager;
    private $niveauRepository;
    private $classeRepository;
    private $carteCarreauRepository;
    private $carteRepository;
    private $caracteristiqueRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        NiveauRepository $niveauRepository,
        ClasseRepository $classeRepository,
        CarteCarreauRepository $carteCarreauRepository,
        CarteRepository $carteRepository,
        CaracteristiqueRepository $caracteristiqueRepository
    ) {
        $this->entityManager = $entityManager;
        $this->niveauRepository = $niveauRepository;
        $this->classeRepository = $classeRepository;
        $this->carteCarreauRepository = $carteCarreauRepository;
        $this->carteRepository = $carteRepository;
        $this->caracteristiqueRepository = $caracteristiqueRepository;
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        $entity = $args->getObject();

        if (!$entity instanceof User) {
            return;
        }

        $user = $entity;
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

        $firstMap = $this->carteRepository->find(GameContent::SPAWN_MAP_ID);
        $user->setMap($firstMap);
        $user->setCaseAbscisse(GameContent::SPAWN_ABSCISSE);
        $user->setCaseOrdonnee(GameContent::SPAWN_ORDONNEE);

        // Initialisation du niveau joueur
        $niveauJoueur = new NiveauJoueur();
        $niveauJoueur->setExperience(0);
        $niveauJoueur->setUser($user);
        $niveau = $this->niveauRepository->findOneBy(['niveau' => 1]);
        $niveauJoueur->setNiveau($niveau);
        $this->entityManager->persist($niveauJoueur);

        // Initialisation de la classe
        $classe = $this->classeRepository->find(GameContent::DEFAULT_CLASSE_ID);
        $user->setClasse($classe);
        $this->entityManager->persist($user);

        // Initialisation de l'inventaire
        $inventaire = new Inventaire();
        $inventaire->setTailleMax(100);
        $inventaire->setUser($user);
        $this->entityManager->persist($inventaire);

        // Initialisation des caractéristiques du joueur
        foreach (GameContent::CARACTERISTIQUE_IDS as $indexCaracteristique) {
            $joueurCaracteristique = new JoueurCaracteristique();
            $joueurCaracteristiqueBonus = new JoueurCaracteristiqueBonus();
            $caracteristique = $this->caracteristiqueRepository->findOneBy(['id' => $indexCaracteristique]);

            // Initialisation des caractéristiques
            $joueurCaracteristique->setUser($user);
            $joueurCaracteristique->setCaracteristique($caracteristique);
            $joueurCaracteristique->setPoints(1);
            $this->entityManager->persist($joueurCaracteristique);

            // Initialisation des caractéristiques d'équipements
            $joueurCaracteristiqueBonus->setJoueur($user);
            $joueurCaracteristiqueBonus->setCaracteristique($caracteristique);
            $joueurCaracteristiqueBonus->setPoints(0);
            $this->entityManager->persist($joueurCaracteristiqueBonus);
        }

        // Placer le joueur sur sa case de spawn (même carte/case que user.map ci-dessus)
        $this->carteCarreauRepository->setPlayerOnCaseInAMap(
            GameContent::SPAWN_MAP_ID,
            GameContent::SPAWN_ABSCISSE,
            GameContent::SPAWN_ORDONNEE,
            $user->getId()
        );

        // Persist tous les changements
        $this->entityManager->flush();
    }
}
