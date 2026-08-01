<?php

namespace App\Event;

use App\Config\JournalConfig;
use App\Entity\User;
use App\Enum\TypeEvenement;
use App\service\JournalService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

/**
 * Marque une connexion réussie : met `user.last_connexion` à jour et consigne l'événement.
 *
 * Deux choses à savoir avant de toucher à ce fichier.
 *
 * **`last_connexion` n'était écrite NULLE PART.** La colonne existait depuis l'origine et
 * restait NULL pour tout le monde ; c'est ici qu'elle prend enfin une valeur. Sans elle, la
 * question « qui a joué cette semaine » n'a aucune source — et c'est précisément ce que le
 * tableau de bord d'administration doit répondre.
 *
 * **Une seule ligne de journal par jour et par joueur** (`JournalConfig::CONNEXION_UNE_PAR_JOUR`).
 * Ce qu'on veut mesurer est « qui a joué ce jour-là », pas « combien de fois le client a
 * renouvelé son jeton ». La comparaison porte sur le JOUR CIVIL et non sur un délai glissant :
 * un joueur qui se reconnecte à 23 h 50 puis à 00 h 10 a bien joué deux jours différents.
 *
 * L'écriture de `last_connexion` se fait en SQL natif, hors unité de travail : un flush
 * complet de l'entité User à chaque authentification réécrirait des champs de partie (vie,
 * PA, position) avec l'état qu'ils avaient au chargement du jeton, ce qui est exactement le
 * genre de résurrection que `DeathService::diePlayer` documente.
 */
class ConnexionSubscriber
{
    public function __construct(
        private readonly JournalService $journalService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $precedente = $user->getLastConnexion();
        $maintenant = new \DateTime('now');

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user SET last_connexion = :maintenant WHERE id = :id',
            ['maintenant' => $maintenant->format('Y-m-d H:i:s'), 'id' => $user->getId()]
        );
        $user->setLastConnexion($maintenant);

        if (JournalConfig::CONNEXION_UNE_PAR_JOUR
            && $precedente !== null
            && $precedente->format('Y-m-d') === $maintenant->format('Y-m-d')) {
            return;
        }

        $this->journalService->consigner(
            type: TypeEvenement::CONNEXION,
            acteur: $user,
        );
    }
}
