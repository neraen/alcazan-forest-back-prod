<?php

namespace App\service;

use App\Entity\Historique;
use App\Entity\User;
use App\Repository\HistoriqueRepository;
use Doctrine\ORM\EntityManagerInterface;

class HistoriqueService{

    private $historiqueRepository;
    private $entityManager;

    public function __construct(HistoriqueRepository $historiqueRepository, EntityManagerInterface $entityManager)
    {
        $this->historiqueRepository = $historiqueRepository;
        $this->entityManager = $entityManager;
    }

    public function recordInHistoryPlayer(User $user, string $message, bool $isExternal): void{
        $now = new \DateTime('now');
        $dateNowFormattedForSql = $now->format('Y-m-d h:m:s');
        $historiqueEntityPlayer = new Historique();
        $historiqueEntityPlayer->setUser($user);
        $historiqueEntityPlayer->setDate($now);
        $historiqueEntityPlayer->setMessage($message);

        $isExternal = $isExternal ? 1 : 0;

        $this->entityManager->getConnection()->executeStatement('INSERT INTO historique (user_id, message, date, is_external) VALUES ("'.$user->getId().'", "'.$message.'", "'.$dateNowFormattedForSql.'", '.$isExternal.')');
    }


}