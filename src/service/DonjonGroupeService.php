<?php

namespace App\service;

use App\Entity\Donjon;
use App\Entity\DonjonGroupe;
use App\Entity\DonjonGroupeMembre;
use App\Entity\DonjonInstance;
use App\Entity\User;
use App\Enum\StatutGroupeDonjon;
use App\Exception\DonjonException;
use App\Repository\DonjonGroupeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine à états du groupe éphémère formé devant la porte d'un donjon.
 * Aucun contrôleur n'écrit dans `donjon_groupe*`.
 *
 * Le groupe ne consomme AUCUN verrou tant qu'il n'est pas lancé : on peut composer,
 * hésiter, se disperser sans avoir « fait » le donjon de la journée. C'est
 * DonjonInstanceService::entrer() qui pose les verrous, au lancement, pour tout le monde
 * d'un coup — et qui refuse le lancement si l'un des inscrits a déjà consommé le sien.
 *
 * Comme pour les instances, l'expiration est PARESSEUSE.
 */
class DonjonGroupeService
{
    /** Un lobby oublié devant la porte se referme tout seul. */
    private const DUREE_VIE_MINUTES = 15;

    public function __construct(
        private readonly DonjonGroupeRepository $groupeRepository,
        private readonly DonjonInstanceService $instanceService,
        private readonly DonjonTeleportService $teleportService,
        private readonly DonjonPublisher $publisher,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /** Le groupe ouvert du joueur, ou null. Constate au passage les lobbies périmés. */
    public function groupeDuJoueur(User $user): ?DonjonGroupe
    {
        $this->expirerLesGroupesPerimes();

        return $this->groupeRepository->findGroupeDuJoueur($user);
    }

    /** @return DonjonGroupe[] */
    public function groupesOuverts(Donjon $donjon): array
    {
        $this->expirerLesGroupesPerimes();

        return $this->groupeRepository->findGroupesOuverts($donjon);
    }

    /* ------------------------------------------------------------------ */
    /* Composition                                                         */
    /* ------------------------------------------------------------------ */

    public function creer(User $user, Donjon $donjon): DonjonGroupe
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $donjon): DonjonGroupe {
            if ($this->groupeRepository->findGroupeDuJoueur($user) !== null) {
                throw new DonjonException("Vous faites déjà partie d'un groupe.");
            }

            $groupe = (new DonjonGroupe(self::DUREE_VIE_MINUTES))
                ->setDonjon($donjon)
                ->setLeader($user);

            $this->entityManager->persist($groupe);
            $this->ajouterMembre($groupe, $user);
            $this->entityManager->flush();

            return $groupe;
        });
    }

    public function rejoindre(User $user, int $groupeId): DonjonGroupe
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $groupeId): DonjonGroupe {
            // Verrou pessimiste : sans lui, deux joueurs peuvent franchir ensemble la
            // dernière place d'un groupe de 5.
            $groupe = $this->entityManager->find(DonjonGroupe::class, $groupeId, LockMode::PESSIMISTIC_WRITE);
            if ($groupe === null || $groupe->getStatut() !== StatutGroupeDonjon::OUVERT) {
                throw new DonjonException("Ce groupe n'est plus ouvert.");
            }

            if ($this->groupeRepository->findGroupeDuJoueur($user) !== null) {
                throw new DonjonException("Vous faites déjà partie d'un groupe.");
            }

            if ($groupe->estComplet()) {
                $max = $groupe->getDonjon()->getTailleGroupeMax();
                throw new DonjonException("Ce groupe est complet ({$max} joueurs).");
            }

            $this->ajouterMembre($groupe, $user);
            $this->entityManager->flush();
            $this->publisher->publierGroupe($groupe);

            return $groupe;
        });
    }

    /**
     * Quitter le lobby. Si le meneur s'en va, le groupe est dissous : c'est lui qui porte
     * la décision de lancer, et un lobby sans meneur ne peut plus rien faire.
     */
    public function quitter(User $user): void
    {
        $groupe = $this->groupeRepository->findGroupeDuJoueur($user);
        if ($groupe === null) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $groupe): void {
            $verrouille = $this->entityManager->find(
                DonjonGroupe::class,
                $groupe->getId(),
                LockMode::PESSIMISTIC_WRITE
            );

            if ($verrouille->getLeader()?->getId() === $user->getId()) {
                $verrouille->setStatut(StatutGroupeDonjon::ANNULE);
                $this->entityManager->persist($verrouille);
                $this->entityManager->flush();
                $this->publisher->publierGroupe($verrouille, 'donjon.groupe.dissous');

                return;
            }

            $membre = $verrouille->membrePour($user);
            if ($membre !== null) {
                $verrouille->removeMembre($membre);
                $this->entityManager->remove($membre);
            }

            $this->entityManager->flush();
            $this->publisher->publierGroupe($verrouille);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Lancement                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Le meneur fait entrer tout le monde : une seule instance, un verrou par joueur,
     * posés ensemble. Si UN inscrit est bloqué (verrou déjà consommé, niveau insuffisant),
     * rien n'est créé — DonjonInstanceService::entrer() jette avant de persister.
     */
    public function lancer(User $user): DonjonInstance
    {
        return $this->entityManager->wrapInTransaction(function () use ($user): DonjonInstance {
            $groupe = $this->groupeRepository->findGroupeDuJoueur($user);
            if ($groupe === null) {
                throw new DonjonException("Vous ne faites partie d'aucun groupe.");
            }

            if ($groupe->getLeader()?->getId() !== $user->getId()) {
                $meneur = $groupe->getLeader()?->getPseudo() ?? 'Le meneur';
                throw new DonjonException("Seul {$meneur} peut lancer l'expédition.");
            }

            if ($groupe->estPerime()) {
                throw new DonjonException("Ce groupe a expiré : reformez-le devant la porte.");
            }

            $compagnons = $groupe->compagnons();
            $instance = $this->instanceService->entrer($user, $groupe->getDonjon(), $compagnons);

            // Le meneur d'abord : il atterrit au plus près de la porte de retour.
            $this->teleportService->placerDansLaSalleDEntree($groupe->getDonjon(), [$user, ...$compagnons]);

            $groupe->setStatut(StatutGroupeDonjon::LANCE);
            $this->entityManager->persist($groupe);
            $this->entityManager->flush();

            $this->publisher->publierLancement($groupe, $instance);

            return $instance;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function ajouterMembre(DonjonGroupe $groupe, User $user): DonjonGroupeMembre
    {
        $membre = (new DonjonGroupeMembre())
            ->setGroupe($groupe)
            ->setUser($user);
        $groupe->addMembre($membre);
        $this->entityManager->persist($membre);

        return $membre;
    }

    private function expirerLesGroupesPerimes(): void
    {
        $perimes = $this->groupeRepository->findPerimes(new \DateTimeImmutable());
        if ($perimes === []) {
            return;
        }

        foreach ($perimes as $groupe) {
            $groupe->setStatut(StatutGroupeDonjon::EXPIRE);
            $this->entityManager->persist($groupe);
        }

        $this->entityManager->flush();
    }
}
