<?php

namespace App\service;

use App\Entity\Echange;
use App\Entity\EchangeLigne;
use App\Entity\User;
use App\Enum\StatutEchange;
use App\Enum\TypeItem;
use App\Enum\TypeRessource;
use App\Exception\EchangeConflitException;
use App\Repository\EchangeLigneRepository;
use App\Repository\EchangeRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine à états des échanges joueur-à-joueur — unique chemin d'invitation,
 * d'acceptation, de modification d'offre, de confirmation et d'annulation.
 *
 * Règles transverses appliquées par chaque opération :
 *  - le joueur courant vient de l'authentification, jamais du payload ;
 *  - chaque mutation verrouille la ligne `echange` (PESSIMISTIC_WRITE) : les actions des
 *    deux joueurs sur une même session sont sérialisées ;
 *  - expiration LAZY : toute session périmée rencontrée passe EXPIRE et libère ses
 *    réservations avant que l'opération ne continue ;
 *  - `expectedVersion` : divergence => EchangeConflitException (HTTP 409 + état frais) ;
 *  - toute modification d'offre invalide LES DEUX confirmations, incrémente la version et
 *    repousse l'expiration ; les ressources proposées sont réservées via SacService ;
 *  - publication Mercure APRÈS commit (un échec de publication n'annule jamais l'action).
 */
class EchangeService
{
    public const ORIGINE = 'echange';
    /** Rayon pour PROPOSER un échange : adjacence stricte, comme les PNJ. */
    public const RAYON_CREATION = 1;
    /** Au-delà, la session ne peut plus être acceptée ni finalisée (le front annule à ≥ 2). */
    public const RAYON_RUPTURE = 2;

    /** @var array<array{0: Echange, 1: string}> publications différées jusqu'à l'après-commit */
    private array $publicationsEnAttente = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EchangeRepository $echangeRepository,
        private readonly EchangeLigneRepository $echangeLigneRepository,
        private readonly UserRepository $userRepository,
        private readonly SacService $sacService,
        private readonly EchangeNormalizer $normalizer,
        private readonly EchangeFinalisationService $finalisationService,
        private readonly EchangePublisher $publisher
    ) {}

    /* ------------------------------------------------------------ invitation */

    public function creer(User $user, int $cibleId): array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $cibleId): Echange {
            $cible = $this->userRepository->find($cibleId);
            if ($cible === null) {
                throw new \DomainException("Ce joueur n'existe pas.");
            }
            if ($cible->getId() === $user->getId()) {
                throw new \DomainException("Vous ne pouvez pas échanger avec vous-même.");
            }
            if (!ProximiteJoueurs::sontProches($user, $cible, self::RAYON_CREATION)) {
                throw new \DomainException("Vous devez être sur une case adjacente pour proposer un échange.");
            }

            $maSession = $this->echangeRepository->findSessionActive($user);
            $this->expirerSiBesoin($maSession);
            if ($maSession !== null && !$maSession->getStatut()->estTerminal()) {
                throw new \DomainException("Vous êtes déjà engagé dans un échange.");
            }

            $saSession = $this->echangeRepository->findSessionActive($cible);
            $this->expirerSiBesoin($saSession);
            if ($saSession !== null && !$saSession->getStatut()->estTerminal()) {
                throw new \DomainException("Ce joueur est déjà occupé par un autre échange.");
            }

            $echange = new Echange();
            $echange->setJoueurUn($user);
            $echange->setJoueurDeux($cible);
            $this->entityManager->persist($echange);

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange, 'echange.invitation');

        return $this->normalizer->normalize($echange);
    }

    public function accepter(User $user, int $echangeId): array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $echangeId): Echange {
            $echange = $this->echangeVerrouille($echangeId);
            if ($echange === null || !$echange->estParticipant($user)) {
                throw new \DomainException("Cette invitation n'existe pas.");
            }
            if ($echange->getJoueurDeux()->getId() !== $user->getId()) {
                throw new \DomainException("Cette invitation ne vous est pas destinée.");
            }

            $this->expirerSiBesoin($echange);
            if ($echange->getStatut() !== StatutEchange::EN_ATTENTE) {
                throw new \DomainException($echange->getStatut() === StatutEchange::EXPIRE
                    ? "Cette invitation a expiré."
                    : "Cette invitation n'est plus valable.");
            }
            if (!ProximiteJoueurs::sontProches($echange->getJoueurUn(), $user, self::RAYON_RUPTURE)) {
                throw new \DomainException("Vous êtes trop éloignés pour ouvrir l'échange.");
            }

            $echange->setStatut(StatutEchange::OUVERT);
            $echange->toucher();

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange, 'echange.ouvert');

        return $this->normalizer->normalize($echange);
    }

    public function refuser(User $user, int $echangeId): array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $echangeId): Echange {
            $echange = $this->echangeVerrouille($echangeId);
            if ($echange === null || $echange->getJoueurDeux()->getId() !== $user->getId()) {
                throw new \DomainException("Cette invitation n'existe pas.");
            }

            // Idempotent : refuser une session déjà close ne change rien.
            if ($echange->getStatut() === StatutEchange::EN_ATTENTE) {
                $this->cloturer($echange, StatutEchange::ANNULE, $user);
            }

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange, 'echange.annule');

        return $this->normalizer->normalize($echange);
    }

    /* ------------------------------------------------------------- lecture */

    /**
     * État courant pour le joueur : sa session active (s'il en a une) et les invitations
     * qu'il a reçues. Sert à la reprise après reconnexion et de secours sans Mercure.
     *
     * @return array{session: ?array, invitations: array[]}
     */
    public function getEtatCourant(User $user): array
    {
        $resultat = $this->entityManager->wrapInTransaction(function () use ($user): array {
            $session = $this->echangeRepository->findSessionActive($user);
            $this->expirerSiBesoin($session);
            if ($session !== null && $session->getStatut()->estTerminal()) {
                $session = null;
            }

            $invitations = [];
            foreach ($this->echangeRepository->findInvitationsRecues($user) as $invitation) {
                $this->expirerSiBesoin($invitation);
                if ($invitation->getStatut() === StatutEchange::EN_ATTENTE) {
                    $invitations[] = $invitation;
                }
            }

            return [$session, $invitations];
        });

        $this->publierEnAttente();
        [$session, $invitations] = $resultat;

        return [
            'session' => $session === null ? null : $this->normalizer->normalize($session),
            'invitations' => array_map(fn (Echange $echange) => $this->normalizer->normalize($echange), $invitations),
        ];
    }

    /* -------------------------------------------------------------- l'offre */

    /** Propose (ou ajuste) un item : `$quantite` est la quantité TOTALE proposée, absolue. */
    public function proposerItem(User $user, TypeItem $type, int $itemId, int $quantite, int $expectedVersion): array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $type, $itemId, $quantite, $expectedVersion): Echange {
            $echange = $this->sessionOuverteVerrouillee($user);
            $this->verifierVersion($echange, $expectedVersion);

            if ($quantite < 1) {
                throw new \DomainException("Quantité invalide.");
            }

            // La réservation vaut contrôle : possession réelle, disponible (hors autres
            // réservations), existence de l'item. Un objet équipé n'est pas dans le sac.
            $this->sacService->reserver(
                $user,
                TypeRessource::fromTypeItem($type),
                $itemId,
                $quantite,
                self::ORIGINE,
                $echange->getId()
            );

            $ligne = $this->echangeLigneRepository->findOneBy([
                'echange' => $echange,
                'proprietaire' => $user,
                'type' => $type,
                'itemId' => $itemId,
            ]);
            if ($ligne === null) {
                $ligne = new EchangeLigne();
                $ligne->setProprietaire($user);
                $ligne->setType($type);
                $ligne->setItemId($itemId);
                $echange->addLigne($ligne);
                $this->entityManager->persist($ligne);
            }
            $ligne->setQuantite($quantite);

            $echange->invaliderConfirmations();
            $echange->toucher();

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange);

        return $this->normalizer->normalize($echange);
    }

    public function retirerItem(User $user, int $ligneId, int $expectedVersion): array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $ligneId, $expectedVersion): Echange {
            $echange = $this->sessionOuverteVerrouillee($user);
            $this->verifierVersion($echange, $expectedVersion);

            $ligne = $this->echangeLigneRepository->find($ligneId);
            if ($ligne === null
                || $ligne->getEchange()->getId() !== $echange->getId()
                || $ligne->getProprietaire()->getId() !== $user->getId()
            ) {
                throw new \DomainException("Cet item ne fait pas partie de votre offre.");
            }

            $this->sacService->reserver(
                $user,
                TypeRessource::fromTypeItem($ligne->getType()),
                $ligne->getItemId(),
                0,
                self::ORIGINE,
                $echange->getId()
            );
            $echange->removeLigne($ligne); // orphanRemoval : la ligne est supprimée

            $echange->invaliderConfirmations();
            $echange->toucher();

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange);

        return $this->normalizer->normalize($echange);
    }

    public function modifierOr(User $user, int $montant, int $expectedVersion): array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $montant, $expectedVersion): Echange {
            $echange = $this->sessionOuverteVerrouillee($user);
            $this->verifierVersion($echange, $expectedVersion);

            if ($montant < 0) {
                throw new \DomainException("Montant invalide.");
            }

            // Réservation d'or en valeur absolue (0 = ne plus rien proposer).
            $this->sacService->reserver($user, TypeRessource::OR, 0, $montant, self::ORIGINE, $echange->getId());
            $echange->setOrPropose($user, $montant);

            $echange->invaliderConfirmations();
            $echange->toucher();

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange);

        return $this->normalizer->normalize($echange);
    }

    /* -------------------------------------------------- confirmation / fin */

    public function confirmer(User $user, int $expectedVersion): array
    {
        $type = 'echange.confirmation';
        $echange = $this->entityManager->wrapInTransaction(function () use ($user, $expectedVersion, &$type): Echange {
            $echange = $this->sessionOuverteVerrouillee($user);
            $this->verifierVersion($echange, $expectedVersion);

            // Double clic / double requête : déjà confirmé => rien à faire.
            if (!$echange->estConfirme($user)) {
                $echange->setConfirme($user, true);
                $echange->toucher();

                if ($echange->lesDeuxOntConfirme()) {
                    // Même transaction, verrou `echange` déjà détenu : la finalisation
                    // verrouille les joueurs, revalide tout et transfère — ou annule TOUT.
                    $this->finalisationService->finaliser($echange);
                    $type = 'echange.complete';
                }
            }

            return $echange;
        });

        $this->publierEnAttente();
        $this->publisher->publierEtat($echange, $type);

        return $this->normalizer->normalize($echange);
    }

    /** Annule la session active du joueur. Idempotent : sans session, ne fait rien. */
    public function annuler(User $user): ?array
    {
        $echange = $this->entityManager->wrapInTransaction(function () use ($user): ?Echange {
            $session = $this->echangeRepository->findSessionActive($user);
            if ($session === null) {
                return null;
            }

            $echange = $this->echangeVerrouille($session->getId());
            if ($echange === null || $echange->getStatut()->estTerminal()) {
                return $echange;
            }

            $this->cloturer($echange, StatutEchange::ANNULE, $user);

            return $echange;
        });

        $this->publierEnAttente();
        if ($echange === null) {
            return null;
        }
        $this->publisher->publierEtat($echange, 'echange.annule');

        return $this->normalizer->normalize($echange);
    }

    /**
     * Expire toutes les sessions périmées (filet lancé par le scheduler ; l'expiration lazy
     * à chaque accès couvre déjà la plupart des cas).
     */
    public function expirerSessionsPerimees(): int
    {
        $expirees = 0;
        foreach ($this->echangeRepository->findExpirees() as $session) {
            $this->entityManager->wrapInTransaction(function () use ($session, &$expirees): void {
                $echange = $this->echangeVerrouille($session->getId());
                if ($echange === null || $echange->getStatut()->estTerminal()) {
                    return; // déjà close par une action concurrente
                }
                $this->expirerSiBesoin($echange);
                if ($echange->getStatut() === StatutEchange::EXPIRE) {
                    ++$expirees;
                }
            });
        }
        $this->publierEnAttente();

        return $expirees;
    }

    /* ------------------------------------------------------------- interne */

    /** Recharge et verrouille la ligne `echange` (les mutations d'une session sont sérialisées). */
    private function echangeVerrouille(int $echangeId): ?Echange
    {
        $echange = $this->echangeRepository->find($echangeId, LockMode::PESSIMISTIC_WRITE);
        if ($echange !== null) {
            // find() peut renvoyer l'entité du cache : on recharge son état sous verrou.
            $this->entityManager->refresh($echange);
        }

        return $echange;
    }

    /** @throws \DomainException si le joueur n'a pas de session OUVERTE */
    private function sessionOuverteVerrouillee(User $user): Echange
    {
        $session = $this->echangeRepository->findSessionActive($user);
        if ($session === null) {
            throw new \DomainException("Vous n'êtes engagé dans aucun échange.");
        }

        $echange = $this->echangeVerrouille($session->getId());
        $this->expirerSiBesoin($echange);

        if ($echange->getStatut() !== StatutEchange::OUVERT) {
            throw new \DomainException(match ($echange->getStatut()) {
                StatutEchange::EN_ATTENTE => "L'échange n'a pas encore été accepté.",
                StatutEchange::EXPIRE => "L'échange a expiré.",
                default => "Cet échange n'est plus ouvert.",
            });
        }

        return $echange;
    }

    private function verifierVersion(Echange $echange, int $expectedVersion): void
    {
        if ($echange->getVersion() !== $expectedVersion) {
            throw new EchangeConflitException($this->normalizer->normalize($echange));
        }
    }

    /** À appeler dans une transaction. Publication différée après commit. */
    private function expirerSiBesoin(?Echange $echange): void
    {
        if ($echange === null || !$echange->estExpire()) {
            return;
        }

        $this->cloturer($echange, StatutEchange::EXPIRE, null);
        $this->publicationsEnAttente[] = [$echange, 'echange.expire'];
    }

    /** Clôture commune : statut terminal, libération des réservations, version. */
    private function cloturer(Echange $echange, StatutEchange $statut, ?User $annulePar): void
    {
        $echange->setStatut($statut);
        $echange->setAnnulePar($annulePar);
        $echange->setCancelledAt(new \DateTimeImmutable());
        $this->sacService->libererReservations(self::ORIGINE, $echange->getId());
        $echange->toucher();
    }

    private function publierEnAttente(): void
    {
        foreach ($this->publicationsEnAttente as [$echange, $type]) {
            $this->publisher->publierEtat($echange, $type);
        }
        $this->publicationsEnAttente = [];
    }
}
