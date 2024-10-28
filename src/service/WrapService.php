<?php

namespace App\service;

use App\Entity\User;
use App\Entity\Wrap;
use App\Repository\NiveauJoueurRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserQueteRepository;

class WrapService
{
    public function __construct(
        private UserBossRepository $userBossRepository,
        private NiveauJoueurRepository $niveauJoueurRepository,
        private UserQueteRepository $userQueteRepository,
    ){
    }

    public function canPlayerChangeMap(User $user, Wrap $wrap): array {
        switch($wrap->getMapCondition()){
            case 'boss':
                return $this->didPlayerKilledBoss($user, $wrap->getValue());
            case 'level':
                return $this->doesPlayerHaveLevel($user, $wrap->getValue());
            case 'alignement':
                return $this->doesPlayerHaveGoodAlignement($user, $wrap->getValue());
            case 'quest':
                return $this->didPlayerFinishedQuest($user, $wrap->getValue());
            default:
                return ['authorization' => true];
        }

    }

    private function didPlayerKilledBoss(User $user, int $bossId): array {
        $userBossEntity = $this->userBossRepository->findOneBy(['user' => $user->getId(), 'boss' => $bossId]);
        $dateTimeNow = new \DateTime('now');

        $interval = $dateTimeNow->getTimestamp() - $userBossEntity->getLastKill()->getTimestamp();

        if(is_null($userBossEntity)){
            $authorization = false;
        }else{
            if($interval < 10800){
                $authorization = true;
            }else{
                $authorization = false;
            }
        }

        $bossName = $userBossEntity->getBoss()->getName();
        return ['authorization' => $authorization,
                'message' => $authorization ? "" : "Vous devez battre $bossName avant d'acceder à la salle aux trésors"];
    }

    private function doesPlayerHaveLevel(User $user, int $requiredLevel): array {
        $niveauJoueur = $this->niveauJoueurRepository->getPlayerLevel($user->getId());

        $authorization = true;
        if($niveauJoueur < $requiredLevel){
            $authorization = false;
        }

        return ['authorization' => $authorization,
                'message' => $authorization ? "" : "Vous devez atteindre le niveau $requiredLevel pour accéder à cet endroit"];
    }

    private function doesPlayerHaveGoodAlignement(User $user, $value): array {
        $authorization = true;
        if($user->getAlignement()->getId() !== $value){
            $authorization = false;
        }

        return ['authorization' => $authorization,
                'message' => $authorization ? "" : "Vous appartenir à l'alignement {$user->getAlignement()->getNom()} pour accéder à cet endroit"];
    }

    private function didPlayerFinishedQuest(User $user, int $questId): array {
        $userQuete = $this->userQueteRepository->findOneBy(['user' => $user, 'quete' => $questId]);
        return ['authorization' => $userQuete->getIsDone(),
                'message' => $userQuete->getIsDone() ? "" : "Vous avoir terminé la quête {$userQuete->getQuete()->getName()} pour accéder à cet endroit "];
    }

}