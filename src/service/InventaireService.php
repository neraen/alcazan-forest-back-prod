<?php


namespace App\service;


use App\Entity\InventaireConsommable;
use App\Entity\InventaireEquipement;
use App\Entity\InventaireObjet;
use App\Entity\User;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\ObjetRepository;
use Doctrine\ORM\EntityManagerInterface;

class InventaireService
{
    public function __construct(
        private readonly InventaireRepository $inventaireRepository,
        private readonly InventaireEquipementRepository $inventaireEquipementRepository,
        private readonly InventaireConsommableRepository $inventaireConsommableRepository,
        private readonly InventaireObjetRepository $inventaireObjetRepository,
        private readonly EquipementRepository $equipementRepository,
        private readonly ConsommableRepository $consommableRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    public function addEquipementToUserInventaire(int $userId, int $idEquipement): void {
        $inventaireEntity = $this->inventaireRepository->findOneBy(['user' => $userId]);
        $shouldIncrementExistingEquipement = $this->inventaireEquipementRepository->findOneBy(['inventaire' => $inventaireEntity->getId(), 'equipement' => $idEquipement]);

        if($shouldIncrementExistingEquipement){
            $shouldIncrementExistingEquipement->setQuantity($shouldIncrementExistingEquipement->getQuantity() + 1);
            $this->entityManager->persist($shouldIncrementExistingEquipement);
            $this->entityManager->flush();
        }else{
            $inventaireEquipementEntity = new InventaireEquipement();
            $equipementEntity = $this->equipementRepository->find($idEquipement);
            $inventaireEquipementEntity->setQuantity(1);
            $inventaireEquipementEntity->setEquipement($equipementEntity);
            $inventaireEquipementEntity->setInventaire($inventaireEntity);
            $this->entityManager->persist($inventaireEquipementEntity);
            $this->entityManager->flush();
        }
    }

    public function addConsommableToUserInventaire(int $userId, int $idConsommable, int $quantity): void {
        $inventaireEntity = $this->inventaireRepository->findOneBy(['user' => $userId]);
        $shouldIncrementExistingConsommable = $this->inventaireConsommableRepository->findOneBy([
            'inventaire' => $inventaireEntity->getId(),
            'consommable' => $idConsommable
        ]);

        if($shouldIncrementExistingConsommable){
            $shouldIncrementExistingConsommable->setQuantity($shouldIncrementExistingConsommable->getQuantity() + $quantity);
            $this->entityManager->persist($shouldIncrementExistingConsommable);
            $this->entityManager->flush();
        }else{
            $inventaireConsommableEntity = new InventaireConsommable();
            $consommableEntity = $this->consommableRepository->find($idConsommable);
            $inventaireConsommableEntity->setQuantity($quantity);
            $inventaireConsommableEntity->setConsommable($consommableEntity);
            $inventaireConsommableEntity->setInventaire($inventaireEntity);
            $this->entityManager->persist($inventaireConsommableEntity);
            $this->entityManager->flush();
        }
    }

    public function addObjetToUserInventaire(int $userId, int $idObjet, int $quantity): void {
        $inventaireEntity = $this->inventaireRepository->findOneBy(['user' => $userId]);
        $shouldIncrementExistingObjet = $this->inventaireObjetRepository->findOneBy([
            'inventaire' => $inventaireEntity->getId(),
            'objet' => $idObjet
        ]);

        if($shouldIncrementExistingObjet){
            $shouldIncrementExistingObjet->setQuantity($shouldIncrementExistingObjet->getQuantity() + $quantity);
            $this->entityManager->persist($shouldIncrementExistingObjet);
            $this->entityManager->flush();
        }else{
            $inventaireObjetEntity = new InventaireObjet();
            $objetEntity = $this->objetRepository->find($idObjet);
            $inventaireObjetEntity->setQuantity($quantity);
            $inventaireObjetEntity->setObjet($objetEntity);
            $inventaireObjetEntity->setInventaire($inventaireEntity);
            $this->entityManager->persist($inventaireObjetEntity);
            $this->entityManager->flush();

        }
    }

    public function giveMoneyToUser(User $user, int $givedMoney): void {
        $initialMoney = $user->getMoney();
        $moneyAfterRecompense = $initialMoney + $givedMoney;
        $user->setMoney($moneyAfterRecompense);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}