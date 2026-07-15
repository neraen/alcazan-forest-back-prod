<?php

namespace App\service;

use App\Entity\Historique;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class HistoriqueService{

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function recordInHistoryPlayer(User $user, string $message, bool $isExternal): void{
        $now = new \DateTime('now');

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO historique (user_id, message, date, is_external) VALUES (?, ?, ?, ?)',
            [$user->getId(), $message, $now->format('Y-m-d H:i:s'), $isExternal ? 1 : 0]
        );
    }


}