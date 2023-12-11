<?php


namespace App\service;


use App\Repository\NiveauJoueurRepository;

class LevelingService
{

    private $niveauJoueurRepository;

    public function __construct(NiveauJoueurRepository $niveauJoueurRepository)
    {
        $this->niveauJoueurRepository = $niveauJoueurRepository;
    }

    public function giveExperienceToAPlayer(int $experience, int $userId): array{
        $levelData = $this->niveauJoueurRepository->getNiveauAndExperience($userId);
        $newExperienceScore = $levelData['experienceActuelle'] + $experience;

        if($newExperienceScore >= $levelData['experienceMax']){
            $resteExperience = $newExperienceScore - $levelData['experienceMax'];
            $newLevel = $levelData['niveau'] + 1;
            $this->niveauJoueurRepository->addExperienceAndUpLevel($userId, $resteExperience, $newLevel);
        }else{
            $this->niveauJoueurRepository->addExperience($userId, $newExperienceScore);
        }

        $newExperienceEntity = $this->niveauJoueurRepository->findOneBy(['user' => $userId]);
        return [
            'experience' => $newExperienceEntity->getExperience(),
            'level' => $newLevel ?? $levelData['niveau']
        ];
    }

    public function giveExpMalusAfterDeath(int $userId): int{
        $levelData = $this->niveauJoueurRepository->getNiveauAndExperience($userId);
        $experienceMaxLevel = $levelData['experienceMax'];

        $newExperienceData = $this->giveExperienceToAPlayer(-$experienceMaxLevel * 0.09, $userId);

        return $newExperienceData['experience'];
    }
}