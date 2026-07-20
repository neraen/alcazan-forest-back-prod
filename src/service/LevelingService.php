<?php


namespace App\service;


use App\Repository\NiveauJoueurRepository;

class LevelingService
{
    public function __construct(private NiveauJoueurRepository $niveauJoueurRepository){}

    // todo : faire une constante partagée (voir aussi addExperienceAndUpLevel)
    private const MAX_LEVEL = 200;

    public function giveExperienceToAPlayer(int $experience, int $userId): array{
        $levelData = $this->niveauJoueurRepository->getNiveauAndExperience($userId);
        $experienceByLevel = $this->niveauJoueurRepository->getExperienceByLevel();

        $startLevel = (int) $levelData['niveau'];
        $level = $startLevel;
        $newExperienceScore = (int) $levelData['experienceActuelle'] + $experience;

        // Consomme le palier de CHAQUE niveau : un gros gain d'XP peut faire monter
        // plusieurs niveaux d'un seul coup (avant, une seule montée par appel — le
        // surplus restait dans la barre et un niveau était pris à chaque gain suivant).
        while ($level < self::MAX_LEVEL
            && isset($experienceByLevel[$level])
            && $newExperienceScore >= $experienceByLevel[$level]) {
            $newExperienceScore -= $experienceByLevel[$level];
            $level++;
        }

        if ($level !== $startLevel) {
            $this->niveauJoueurRepository->addExperienceAndUpLevel($userId, $newExperienceScore, $level);
        } else {
            $this->niveauJoueurRepository->addExperience($userId, $newExperienceScore);
        }

        return [
            'experience' => $newExperienceScore,
            'level' => $level,
            'experienceMax' => $experienceByLevel[$level] ?? (int) $levelData['experienceMax'],
        ];
    }

    public function giveExpMalusAfterDeath(int $userId): int{
        $levelData = $this->niveauJoueurRepository->getNiveauAndExperience($userId);
        $experienceMaxLevel = $levelData['experienceMax'];

        $newExperienceData = $this->giveExperienceToAPlayer(-$experienceMaxLevel * 0.09, $userId);

        return $newExperienceData['experience'];
    }
}