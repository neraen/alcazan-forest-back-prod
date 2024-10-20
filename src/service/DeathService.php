<?php


namespace App\service;


use App\Entity\InventaireEquipement;
use App\Entity\InventaireObjet;
use App\Entity\MonstreCarreau;
use App\Entity\User;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\JoueurCaracteristiqueRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class DeathService
{

    public function __construct(
        private MapService $mapService,
        private LevelingService $levelingService,
        private InventaireRepository $inventaireRepository,
        private InventaireObjetRepository $inventaireObjetRepository,
        private InventaireEquipementRepository $inventaireEquipementRepository,
        private CarteRepository $carteRepository,
        private CarteCarreauRepository $carteCarreauRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager
    ){}

    public function dieMonster(MonstreCarreau $monstreCarreau, User $user): array{
        $newQuantity = $monstreCarreau->getQuantity()-1;
        $monstreCarreau->setQuantity($newQuantity);
        $maxLife = $monstreCarreau->getMonstre()->getMaxLife();
        $monstreCarreau->setCurrentLife($maxLife);
        $this->entityManager->persist($monstreCarreau);
        $this->entityManager->flush();
        $monstreObjet = $monstreCarreau->getMonstre()->getMonstreObjets();
        $droppedItems = [];

        foreach ($monstreObjet as $drop){
            $proba = $drop->getTauxDrop();
            $maxRand = $drop->getDiviseurTauxDrop();
            $rand = rand(1, $maxRand);
            if($rand <= $proba){
                $droppedItems[] = $drop->getObjet()->getName();
                $inventaire = $this->inventaireRepository->findOneBy(['user' => $user]);
                if($drop->getTypeDrop() === "objet"){
                    $shouldIncrementObjetInInventory = $this->inventaireObjetRepository->findOneBy(['inventaire' => $inventaire, 'objet' => $drop->getObjet()]);
                    if($shouldIncrementObjetInInventory){
                        $shouldIncrementObjetInInventory->setQuantity($shouldIncrementObjetInInventory->getQuantity()+1);

                        $this->entityManager->persist($shouldIncrementObjetInInventory);
                        $this->entityManager->flush();
                    }else{
                        $inventaireObjet = new InventaireObjet();
                        $inventaireObjet->setInventaire($inventaire);
                        $inventaireObjet->setObjet($drop->getObjet());
                        $inventaireObjet->setQuantity(1);

                        $this->entityManager->persist($inventaireObjet);
                        $this->entityManager->flush();
                    }
                }else{
                    /*TODO: implémenter la possibilité de drop un equipement */
//                    $shouldIncrementEquipementInventory = $this->inventaireEquipementRepository->findOneBy(['inventaire' => $inventaire, 'equipement' => $drop->getObjet()]);
//                    if($shouldIncrementObjetInInventory){
//                        $shouldIncrementObjetInInventory->setQuantity($shouldIncrementObjetInInventory->getQuantity()+1);
//
//                        $this->entityManager->persist($shouldIncrementObjetInInventory);
//                        $this->entityManager->flush();
//                    }else{
//                        $inventaireObjet = new InventaireObjet();
//                        $inventaireObjet->setInventaire($inventaire);
//                        $inventaireObjet->setObjet($drop->getObjet());
//                        $inventaireObjet->setQuantity(1);
//
//                        $this->entityManager->persist($inventaireObjet);
//                        $this->entityManager->flush();
//                    }
                }
            }
        }

        return $droppedItems;
    }

    public function diePlayer(User $user): array{
        $initialMap = $this->carteRepository->find($user->getMap()->getId());
        $cimetieres = $this->carteRepository->findBy(['is_cimetiere' => true]);
        $nearestCimetiere = $this->mapService->getNearestMapInList($initialMap, $cimetieres);
        $experience = $this->levelingService->giveExpMalusAfterDeath($user->getId());

        /** todo renommer cette fonction et prendre que l'id user en params */
        $this->carteCarreauRepository->updatePlayerInCase($user);

        /**  todo faire une requete à la main */
        $startTime = new \DateTime("now");
        $startTime->modify('+30 seconds');


        $this->userRepository->updateJoueurInfoAfterDeath($startTime, $nearestCimetiere->getId(), 11, 10,
            $user->getMaxLife(), $user->getMaxMana(), $user->getId());

//        $userEntity->setSummoningSickness($startTime);
//        $userEntity->setMap($nearestCimetiere);
//        $userEntity->setCaseAbscisse(11);
//        $userEntity->setCaseOrdonnee(10);
//        $userEntity->setCurrentLife($userEntity->getMaxLife());
//        $userEntity->setCurrentMana($userEntity->getMaxMana());
//        dump($userEntity);
//        $this->entityManager->persist($userEntity);
//        $this->entityManager->flush();
        $this->carteCarreauRepository->setPlayerOnCaseInAMap($nearestCimetiere->getId(), 11, 10, $user->getId());

        return [
            'experience' => $experience,
            'mapId' => $nearestCimetiere->getId()
        ];
    }

}