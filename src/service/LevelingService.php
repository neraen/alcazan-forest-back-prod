<?php


namespace App\service;


use App\Enum\TypeCumul;
use App\Enum\TypeEvenement;
use App\Repository\NiveauJoueurRepository;
use App\Repository\UserRepository;

class LevelingService
{
    public function __construct(
        private NiveauJoueurRepository $niveauJoueurRepository,
        private UserRepository $userRepository,
        private JournalService $journalService,
        private CumulJoueurService $cumulJoueurService,
    ){}

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

        // Le cumul est alimenté par identifiant, sans charger l'entité : voir
        // `CumulJoueurService::ajouterParId`. Un pas ≤ 0 est ignoré par le service, ce qui
        // suffirait — mais le test de signe est explicite ici parce que le cas n'a rien
        // d'accidentel : `giveExpMalusAfterDeath` passe délibérément une valeur négative, et
        // un malus de mort n'est pas de l'XP « dé-gagnée ».
        if ($experience > 0) {
            $this->cumulJoueurService->ajouterParId($userId, TypeCumul::XP_TOTALE, $experience);
        }

        $this->journaliser($userId, $experience, $level, $startLevel);

        return [
            'experience' => $newExperienceScore,
            'level' => $level,
            'experienceMax' => $experienceByLevel[$level] ?? (int) $levelData['experienceMax'],
        ];
    }

    /**
     * L'état de progression SANS rien y changer, dans la forme exacte que renvoie
     * `giveExperienceToAPlayer`.
     *
     * Existe parce que « aucune XP gagnée » n'est pas « aucun niveau ». Les appelants qui
     * n'accordaient pas d'XP renvoyaient `level: null` et `newExperience: null` au front —
     * or `UsernameBlock` s'affiche sous condition de `joueurState.level`, et le mettre à
     * null remplace la fiche du joueur par un chargement perpétuel jusqu'au F5. Le contrat
     * partagé des endpoints d'attaque (doc §21.13 bis) veut une VALEUR, pas un trou.
     *
     * @return array{experience: int, level: int, experienceMax: int}
     */
    public function etatDe(int $userId): array
    {
        $levelData = $this->niveauJoueurRepository->getNiveauAndExperience($userId);

        return [
            'experience' => (int) $levelData['experienceActuelle'],
            'level' => (int) $levelData['niveau'],
            'experienceMax' => (int) $levelData['experienceMax'],
        ];
    }

    public function giveExpMalusAfterDeath(int $userId): int{
        $levelData = $this->niveauJoueurRepository->getNiveauAndExperience($userId);
        $experienceMaxLevel = $levelData['experienceMax'];

        $newExperienceData = $this->giveExperienceToAPlayer(-$experienceMaxLevel * 0.09, $userId);

        return $newExperienceData['experience'];
    }

    /**
     * Consigne le gain d'XP et, le cas échéant, la montée de niveau.
     *
     * Trois précautions qui expliquent les conditions :
     *
     *  - **Un gain nul n'est pas un fait.** Plusieurs chemins appellent cette méthode avec 0
     *    (sorts de buff notamment) ; « a gagné 0 point d'expérience » serait du bruit pur.
     *  - **Un gain négatif n'est pas un gain.** Le malus de mort passe par ici avec une
     *    valeur négative ; il est déjà raconté par l'événement `MORT_JOUEUR`, et le compter
     *    comme de l'XP « dé-gagnée » fausserait aussi bien le journal que les futurs cumuls.
     *  - **La montée de niveau est un fait distinct**, et rare : c'est ce qui la rend digne
     *    de sa propre ligne là où l'XP, elle, est un flux.
     *
     * `find()` est un accès à l'identity map dans tous les appelants actuels — le joueur y
     * est déjà chargé (`$this->getUser()` dans les contrôleurs, `$user` dans
     * `RecompenseService`) — donc ce n'est pas un aller-retour base sur un chemin chaud.
     */
    private function journaliser(int $userId, int $experience, int $level, int $startLevel): void
    {
        if ($experience <= 0 && $level === $startLevel) {
            return;
        }

        $user = $this->userRepository->find($userId);
        if ($user === null) {
            return;
        }

        if ($experience > 0) {
            $this->journalService->consigner(
                type: TypeEvenement::XP_GAGNEE,
                acteur: $user,
                quantite: $experience,
            );
        }

        if ($level !== $startLevel) {
            $this->journalService->consigner(
                type: TypeEvenement::NIVEAU_ATTEINT,
                acteur: $user,
                quantite: $level,
                contexte: ['niveauPrecedent' => $startLevel],
            );
        }
    }
}
