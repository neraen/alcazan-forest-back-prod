<?php


namespace App\service;


use App\Entity\User;
use App\Repository\CaracteristiqueRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\NiveauJoueurRepository;

class CaracteristiqueService
{
    public function __construct(
        private readonly JoueurCaracteristiqueBonusRepository $joueurCaracteristiqueBonusRepository,
        private readonly CaracteristiqueRepository $caracteristiqueRepository
    )
    {

    }

    public function getPlayerArmor(User $user): ?int {
        $armureCaracEntity = $this->caracteristiqueRepository->findOneBy(['nom' => 'armure']);
        $caracteristiqueBonusJoueurEntity = $this->joueurCaracteristiqueBonusRepository->findOneBy(['joueur' => $user, 'caracteristique' => $armureCaracEntity]);
        $armorPoints = !empty($caracteristiqueBonusJoueurEntity) ? $caracteristiqueBonusJoueurEntity->getPoints() : 0;
        return $armorPoints;
    }
}