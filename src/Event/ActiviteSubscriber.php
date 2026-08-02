<?php

namespace App\Event;

use App\Config\PresenceConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * Entretient `user.derniere_activite` : la source de la pastille « en ligne » de l'étiquette
 * de survol.
 *
 * ## Pourquoi `kernel.controller` et non `kernel.request`
 *
 * L'authentification du pare-feu s'exécute PENDANT `kernel.request` : un écouteur posé là
 * verrait un jeton de sécurité vide une fois sur deux selon la priorité, et la présence
 * dépendrait d'un chiffre d'ordonnancement. À `kernel.controller`, l'utilisateur est
 * définitivement résolu — ou définitivement absent, et il n'y a rien à faire.
 *
 * ## Pourquoi un UPDATE natif et pas un flush
 *
 * Même raison que `ConnexionSubscriber` : flusher l'entité `User` à chaque requête réécrirait
 * ses champs de partie (vie, PA, position) avec l'état qu'ils avaient au chargement du jeton.
 * C'est le mécanisme de résurrection que `DeathService::diePlayer` documente — un joueur mort
 * pendant la requête reviendrait à la vie parce qu'on a voulu noter qu'il était en ligne.
 *
 * L'écriture est bornée à une par `PresenceConfig::RAFRAICHISSEMENT_SECONDES` : un simple
 * déplacement déclenche plusieurs requêtes authentifiées, et la présence n'a pas besoin
 * d'être à la seconde.
 */
class ActiviteSubscriber
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || PresenceConfig::estAJour($user->getDerniereActivite())) {
            return;
        }

        $maintenant = new \DateTime('now');

        // L'activité ne doit JAMAIS faire échouer une requête de jeu : même invariant que le
        // journal. Une base momentanément indisponible fera afficher « hors ligne », ce qui
        // est cosmétique ; une exception ici casserait l'action que le joueur a demandée.
        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user SET derniere_activite = :maintenant WHERE id = :id',
                ['maintenant' => $maintenant->format('Y-m-d H:i:s'), 'id' => $user->getId()]
            );
            $user->setDerniereActivite($maintenant);
        } catch (\Throwable) {
            // volontairement silencieux
        }
    }
}
