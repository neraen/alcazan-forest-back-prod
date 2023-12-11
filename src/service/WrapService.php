<?php


namespace App\service;

use App\Entity\User;
use App\Entity\Wrap;
use App\Repository\BossRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\UserBossRepository;
use Doctrine\ORM\EntityManagerInterface;


class WrapService
{

    private $bossRepository;
    private $userBossRepository;
    private $niveauJoueurRepository;
    private $entityManager;

    public function __construct(
        BossRepository $bossRepository,
        UserBossRepository $userBossRepository,
        NiveauJoueurRepository $niveauJoueurRepository,
        EntityManagerInterface $entityManager
    )
    {
        $this->userBossRepository = $userBossRepository;
        $this->bossRepository = $bossRepository;
        $this->niveauJoueurRepository = $niveauJoueurRepository;
        $this->entityManager = $entityManager;
    }

    public function canPlayerChangeMap(User $user, Wrap $wrap): array{
        switch($wrap->getMapCondition()){
            case 'boss':
                return $this->didPlayerKilledBoss($user, $wrap->getValue());
            case 'level':
                return $this->doesPlayerHaveLevel($user, $wrap->getValue());
            case 'alignement':
                return $this->doesPlayerHaveGoodAlignement($user, $wrap->getValue());
            default:
                return ['authorization' => true];
        }

    }

    private function didPlayerKilledBoss(User $user, $value): array{
        //$boss = $this->bossRepository->find($value);
        $userBossEntity = $this->userBossRepository->findOneBy(['user' => $user->getId(), 'boss' => $value]);
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

        return ['authorization' => $authorization,
                'message' => $authorization ? "" : "Vous devez battre le boss avant d'acceder à la salle aux trésors"];
    }

    private function doesPlayerHaveLevel(User $user, $value){
        $niveauJoueur = $this->niveauJoueurRepository->getPlayerLevel($user->getId());

        $authorization = true;
        if($niveauJoueur['niveau'] < $value){
            $authorization = false;
        }

        return ['authorization' => $authorization,
                'message' => $authorization ? "" : "Vous devez atteindre le niveau $value pour accéder à cet endroit"];
    }

    private function doesPlayerHaveGoodAlignement(User $user, $value){
        $authorization = true;
        if($user->getAlignement()->getId() !== $value){
            $authorization = false;
        }

        return ['authorization' => $authorization,
                'message' => $authorization ? "" : "Vous appartenir à l'alignement {$user->getAlignement()->getNom()} pour accéder à cet endroit"];
    }

}