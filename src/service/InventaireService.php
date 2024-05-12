<?php


namespace App\service;


use App\Entity\InventaireEquipement;
use App\Entity\User;
use App\Repository\EquipementRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireRepository;
use Doctrine\ORM\EntityManagerInterface;

class InventaireService
{
    public function __construct(
        private readonly InventaireRepository $inventaireRepository,
        private readonly InventaireEquipementRepository $inventaireEquipementRepository,
        private readonly EquipementRepository $equipementRepository,
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
            $equipementEntity = $this->equipementRepository->findOneBy(['id' =>  $idEquipement]);
            $inventaireEquipementEntity->setQuantity(1);
            $inventaireEquipementEntity->setEquipement($equipementEntity);
            $inventaireEquipementEntity->setInventaire($inventaireEntity);
            $this->entityManager->persist($inventaireEquipementEntity);
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