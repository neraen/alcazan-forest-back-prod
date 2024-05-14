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
            $newExperienceScore = $newExperienceScore - $levelData['experienceMax'];
            // todo : faire une constante
            if($levelData['niveau'] < 200){
                $newLevel = $levelData['niveau'] + 1;
                $this->niveauJoueurRepository->addExperienceAndUpLevel($userId, $newExperienceScore, $newLevel);
            }

        }else{
            $this->niveauJoueurRepository->addExperience($userId, $newExperienceScore);
        }

        return [
            'experience' => $newExperienceScore,
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