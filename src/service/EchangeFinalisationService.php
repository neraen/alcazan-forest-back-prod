<?php

namespace App\service;

use App\Entity\Echange;
use App\Entity\EchangeLigne;
use App\Entity\User;
use App\Enum\StatutEchange;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finalisation ATOMIQUE d'un échange : soit tout est transféré, soit rien ne change.
 *
 * Appelée par EchangeService::confirmer, DANS la transaction qui détient déjà le verrou
 * pessimiste sur la ligne `echange`. Ici on verrouille en plus les deux joueurs (par id
 * croissant, ordre déterministe anti-deadlock), on revalide TOUT sous verrou, puis on
 * transfère via SacService. La moindre erreur (\DomainException) fait tout annuler —
 * y compris la confirmation qui a déclenché la finalisation.
 */
class EchangeFinalisationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SacService $sacService
    ) {}

    /**
     * @throws \DomainException si une condition n'est plus remplie — la transaction englobante
     *                          est alors annulée sans aucun transfert partiel
     */
    public function finaliser(Echange $echange): void
    {
        // Verrous joueurs par id croissant. Les entités User déjà chargées peuvent être
        // périmées : le refresh recharge leur état (or, position) sous verrou.
        $joueurs = [$echange->getJoueurUn(), $echange->getJoueurDeux()];
        usort($joueurs, fn (User $premier, User $second) => $premier->getId() <=> $second->getId());
        foreach ($joueurs as $joueur) {
            $this->entityManager->find(User::class, $joueur->getId(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($joueur);
        }

        $joueurUn = $echange->getJoueurUn();
        $joueurDeux = $echange->getJoueurDeux();

        // Revalidation complète sous verrou : rien de ce qui a été affiché ne fait foi.
        if ($echange->getStatut() !== StatutEchange::OUVERT) {
            throw new \DomainException("Cet échange n'est plus ouvert.");
        }
        if (!$echange->lesDeuxOntConfirme()) {
            throw new \DomainException("Les deux joueurs doivent confirmer l'échange.");
        }
        if ($echange->estExpire()) {
            throw new \DomainException("L'échange a expiré.");
        }
        if (!ProximiteJoueurs::sontProches($joueurUn, $joueurDeux, EchangeService::RAYON_RUPTURE)) {
            throw new \DomainException("Vous êtes trop éloignés pour conclure l'échange.");
        }

        // Les réservations de CET échange sont libérées d'abord : le disponible redevient
        // le possédé réel, et les retraits/débits ci-dessous revérifient tout. Le flush rend
        // les suppressions visibles des requêtes SUM de SacService (même transaction).
        $this->sacService->libererReservations(EchangeService::ORIGINE, $echange->getId());
        $this->entityManager->flush();

        // Transferts croisés des items…
        foreach ($echange->getLignes() as $ligne) {
            /** @var EchangeLigne $ligne */
            $donneur = $ligne->getProprietaire();
            $receveur = $echange->getPartenaire($donneur);
            $this->sacService->retirerItem($donneur, $ligne->getType(), $ligne->getItemId(), $ligne->getQuantite());
            $this->sacService->ajouterItem($receveur, $ligne->getType(), $ligne->getItemId(), $ligne->getQuantite());
        }

        // …puis de l'or : les DEUX débits avant les crédits, pour que chacun paie avec
        // l'or qu'il possède vraiment, jamais avec celui qu'il est en train de recevoir.
        $this->sacService->debiterOr($joueurUn, $echange->getOrPropose($joueurUn));
        $this->sacService->debiterOr($joueurDeux, $echange->getOrPropose($joueurDeux));
        $this->sacService->crediterOr($joueurDeux, $echange->getOrPropose($joueurUn));
        $this->sacService->crediterOr($joueurUn, $echange->getOrPropose($joueurDeux));

        // La session complétée reste en base avec ses lignes : c'est l'audit minimal.
        $echange->setStatut(StatutEchange::COMPLETE);
        $echange->setCompletedAt(new \DateTimeImmutable());
        $echange->toucher();
    }
}
