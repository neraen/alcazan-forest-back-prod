<?php

namespace App\service;

use App\Entity\User;
use App\Repository\CaracteristiqueRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\JoueurCaracteristiqueRepository;

class CaracteristiqueService
{
    public function __construct(
        private readonly JoueurCaracteristiqueBonusRepository $joueurCaracteristiqueBonusRepository,
        private readonly JoueurCaracteristiqueRepository $joueurCaracteristiqueRepository,
        private readonly CaracteristiqueRepository $caracteristiqueRepository
    ){
    }

    /** Armure totale du joueur : points investis + bonus d'équipement. */
    public function getPlayerArmor(User $user): int {
        $armureCaracEntity = $this->caracteristiqueRepository->findOneBy(['nom' => 'armure']);

        $bonusEntity = $this->joueurCaracteristiqueBonusRepository->findOneBy(['joueur' => $user, 'caracteristique' => $armureCaracEntity]);
        $pointsEntity = $this->joueurCaracteristiqueRepository->findOneBy(['user' => $user, 'caracteristique' => $armureCaracEntity]);

        return ($bonusEntity?->getPoints() ?? 0) + ($pointsEntity?->getPoints() ?? 0);
    }
}