<?php

namespace App\service;

use App\Entity\Echange;
use App\Entity\EchangeLigne;
use App\Entity\User;
use App\Enum\StatutEchange;
use App\Enum\TypeCumul;
use App\Enum\TypeEvenement;
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
        private readonly SacService $sacService,
        private readonly JournalService $journalService,
        private readonly CumulJoueurService $cumulJoueurService
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

        // Chacun a dépensé ce qu'il proposait et gagné ce que l'autre proposait. Un pas nul
        // est ignoré par le service, donc un échange sans or n'écrit aucune ligne de cumul.
        $this->cumulJoueurService->ajouter($joueurUn, TypeCumul::OR_DEPENSE, $echange->getOrPropose($joueurUn));
        $this->cumulJoueurService->ajouter($joueurDeux, TypeCumul::OR_DEPENSE, $echange->getOrPropose($joueurDeux));
        $this->cumulJoueurService->ajouter($joueurUn, TypeCumul::OR_GAGNE, $echange->getOrPropose($joueurDeux));
        $this->cumulJoueurService->ajouter($joueurDeux, TypeCumul::OR_GAGNE, $echange->getOrPropose($joueurUn));

        // UNE ligne de journal pour UN fait, alors que l'échange vient de faire six à dix
        // appels à SacService : c'est exactement pourquoi le journal s'écrit chez l'appelant
        // et jamais au centre. Le nom des items est FIGÉ ici — `echange_ligne.item_id` n'a
        // pas de clé étrangère, donc aucune requête ne pourra le retrouver plus tard.
        //
        // `montantOr` porte le total déplacé (les deux sens) : c'est le flux que le tableau
        // de bord somme. Qui a donné quoi à qui reste lisible dans le contexte.
        $this->journalService->consigner(
            type: TypeEvenement::ECHANGE_CONCLU,
            acteur: $joueurUn,
            cibleUser: $joueurDeux,
            montantOr: $echange->getOrPropose($joueurUn) + $echange->getOrPropose($joueurDeux),
            contexte: [
                'echangeId' => $echange->getId(),
                'items' => $this->figerLignes($echange),
                'orJoueurUn' => $echange->getOrPropose($joueurUn),
                'orJoueurDeux' => $echange->getOrPropose($joueurDeux),
            ],
        );

        // La session complétée reste en base avec ses lignes : c'est l'audit minimal.
        $echange->setStatut(StatutEchange::COMPLETE);
        $echange->setCompletedAt(new \DateTimeImmutable());
        $echange->toucher();
    }

    /**
     * Les lignes de l'échange, nom figé et SENS conservé (`de` → `vers`).
     *
     * La direction vit dans la liste elle-même plutôt que dans deux listes séparées :
     * `TypeEvenement::phrase()` peut alors l'ignorer et énumérer ce qui a changé de mains,
     * pendant que la fiche d'enquête, elle, lit qui a donné quoi.
     *
     * @return list<array>
     */
    private function figerLignes(Echange $echange): array
    {
        $lignes = [];
        foreach ($echange->getLignes() as $ligne) {
            /** @var EchangeLigne $ligne */
            $donneur = $ligne->getProprietaire();
            $fige = $this->journalService->figerItems([[
                'type' => $ligne->getType(),
                'id' => $ligne->getItemId(),
                'quantite' => $ligne->getQuantite(),
            ]])[0];

            $fige['de'] = $donneur->getId();
            $fige['vers'] = $echange->getPartenaire($donneur)->getId();
            $lignes[] = $fige;
        }

        return $lignes;
    }
}
