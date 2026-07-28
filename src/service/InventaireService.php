<?php

namespace App\service;

use App\Entity\User;
use App\Enum\TypeItem;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @deprecated Adaptateur de compatibilité : toute la logique vit dans SacService (unique point
 *             de mutation items/or). Ne pas ajouter de nouvelle méthode ici — appeler SacService
 *             directement, dans une transaction (`wrapInTransaction`).
 *
 * Le flush est conservé pour les appelants historiques (récompenses de quêtes) : à l'intérieur
 * d'une transaction englobante il n'engage rien, hors transaction il préserve l'ancien
 * comportement de sauvegarde immédiate.
 */
class InventaireService
{
    public function __construct(
        private readonly SacService             $sacService,
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function addEquipementToUserInventaire(int $userId, int $idEquipement): void
    {
        $this->sacService->ajouterItem($this->getUser($userId), TypeItem::EQUIPEMENT, $idEquipement, 1);
        $this->entityManager->flush();
    }

    public function addConsommableToUserInventaire(int $userId, int $idConsommable, int $quantity): void
    {
        $this->sacService->ajouterItem($this->getUser($userId), TypeItem::CONSOMMABLE, $idConsommable, $quantity);
        $this->entityManager->flush();
    }

    public function addObjetToUserInventaire(int $userId, int $idObjet, int $quantity): void
    {
        $this->sacService->ajouterItem($this->getUser($userId), TypeItem::OBJET, $idObjet, $quantity);
        $this->entityManager->flush();
    }

    public function giveMoneyToUser(User $user, int $givedMoney): void
    {
        $this->sacService->crediterOr($user, $givedMoney);
        $this->entityManager->flush();
    }

    private function getUser(int $userId): User
    {
        $user = $this->userRepository->find($userId);
        if ($user === null) {
            throw new \DomainException("Joueur introuvable.");
        }

        return $user;
    }
}
