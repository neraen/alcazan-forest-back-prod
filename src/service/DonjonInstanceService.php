<?php

namespace App\service;

use App\Entity\Boss;
use App\Entity\Donjon;
use App\Entity\DonjonInstance;
use App\Entity\DonjonInstanceMembre;
use App\Entity\DonjonVerrou;
use App\Entity\User;
use App\Enum\StatutInstanceDonjon;
use App\Enum\TypeSalleDonjon;
use App\Exception\DonjonException;
use App\Repository\CarteCarreauRepository;
use App\Repository\DonjonInstanceRepository;
use App\Repository\DonjonRepository;
use App\Repository\DonjonSalleRepository;
use App\Repository\DonjonVerrouRepository;
use App\Repository\NiveauJoueurRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * LA machine à états des donjons — unique chemin d'entrée, de sortie, de verrouillage
 * quotidien et de mutation de la vie du boss d'instance. Aucun contrôleur ni autre
 * service ne doit écrire dans `donjon_instance*` / `donjon_verrou`.
 *
 * Deux invariants portent tout le reste :
 *
 *  1. **Le décor n'est jamais dupliqué.** Une salle de donjon reste une carte unique en
 *     base ; c'est l'OCCUPATION qui est virtualisée (cf. DonjonMapView). En instance,
 *     `carte_carreau.joueur_id` n'est plus écrit — cette colonne est un OneToOne global,
 *     donc structurellement incapable de porter plusieurs groupes sur la même carte.
 *
 *  2. **Le verrou est lié à l'instance**, pas au fait d'être entré. Tant que le jour de
 *     donjon n'a pas tourné, le joueur retrouve SON instance (même terminée, pour
 *     ramasser son butin) et ne peut pas en obtenir une neuve.
 *
 * L'expiration est PARESSEUSE (patron d'EchangeService) : constatée au fil des requêtes,
 * pas par une tâche planifiée.
 */
class DonjonInstanceService
{
    public function __construct(
        private readonly DonjonRepository $donjonRepository,
        private readonly DonjonSalleRepository $donjonSalleRepository,
        private readonly DonjonInstanceRepository $instanceRepository,
        private readonly DonjonVerrouRepository $verrouRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /** Le donjon auquel appartient cette carte, ou null si c'est une carte du monde ouvert. */
    public function donjonDeLaCarte(int $carteId): ?Donjon
    {
        return $this->donjonSalleRepository->findOneByCarte($carteId)?->getDonjon();
    }

    /**
     * L'instance dans laquelle le joueur se trouve actuellement, ou null.
     * Constate au passage l'expiration des instances périmées.
     */
    public function instanceCourante(User $user): ?DonjonInstance
    {
        $this->expirerLesInstancesPerimees();

        return $this->instanceRepository->findInstanceCourante($user);
    }

    /**
     * L'instance que le verrou du jour réserve au joueur sur ce donjon, terminée ou non.
     * C'est elle qu'on rejoint plutôt que d'en créer une neuve.
     */
    public function instanceVerrouillee(User $user, Donjon $donjon): ?DonjonInstance
    {
        return $this->verrouRepository
            ->findPourJour($user, $donjon, $this->jourDeDonjon($donjon))
            ?->getInstance();
    }

    /**
     * Peut-on encore entrer dans cette instance ?
     *
     * TERMINEE et ABANDONNEE restent rejoignables — on revient ramasser le coffre, ou
     * simplement reprendre l'expédition qu'on avait quittée. Seule l'EXPIRATION ferme la
     * porte : la durée max est écoulée.
     *
     * Le test porte AUSSI sur `expireAt`, pas seulement sur le statut : l'expiration est
     * paresseuse, une instance périmée peut donc être encore marquée EN_COURS en base
     * jusqu'à ce que quelqu'un la constate. Sans cette seconde condition, l'interface
     * proposerait de retourner dans une expédition que l'entrée refuserait juste après.
     *
     * C'est le SEUL endroit où cette règle est écrite : `rejoindre()` s'en sert pour
     * refuser, le normalizer pour dire au front quel bouton afficher.
     */
    public function peutRejoindre(DonjonInstance $instance, ?\DateTimeImmutable $maintenant = null): bool
    {
        if ($instance->getStatut() === StatutInstanceDonjon::EXPIREE) {
            return false;
        }

        $expireAt = $instance->getExpireAt();

        return $expireAt === null || $expireAt > ($maintenant ?? new \DateTimeImmutable());
    }

    /**
     * Le « jour de donjon » courant : la date décalée de l'heure de reset.
     * Avec un reset à 5 h, tout ce qui se passe entre minuit et 5 h compte pour la veille,
     * ce qui évite qu'une session nocturne consomme deux verrous.
     *
     * `donjon.heure_reset` s'entend en HEURE DE PARIS, pas en heure serveur (PHP tourne en
     * UTC) : l'admin qui saisit « 5 » veut 5 h du matin pour ses joueurs, et le décalage
     * d'été aurait sinon donné 7 h à l'écran.
     *
     * La comparaison porte sur l'heure murale (`format('G')`) et non sur une soustraction
     * de timestamp : aux changements d'heure, retrancher 5 h décalerait la frontière d'une
     * heure supplémentaire.
     */
    public function jourDeDonjon(Donjon $donjon, ?\DateTimeImmutable $maintenant = null): \DateTimeImmutable
    {
        $local = ($maintenant ?? new \DateTimeImmutable())->setTimezone(self::fuseauDeJeu());
        $jour = $local->setTime(0, 0);

        return (int)$local->format('G') < $donjon->getHeureReset()
            ? $jour->modify('-1 day')
            : $jour;
    }

    /** Moment du prochain reset quotidien — sert à annoncer au joueur quand il pourra revenir. */
    public function prochainReset(Donjon $donjon, ?\DateTimeImmutable $maintenant = null): \DateTimeImmutable
    {
        return $this->jourDeDonjon($donjon, $maintenant)
            ->modify('+1 day')
            ->setTime($donjon->getHeureReset(), 0);
    }

    /**
     * Fuseau de référence du jeu. Les DURÉES (expiration d'instance, délai d'une zone,
     * vie d'un lobby) restent en UTC : seul le reset QUOTIDIEN a besoin d'une heure murale.
     */
    private static function fuseauDeJeu(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Paris');
    }

    /* ------------------------------------------------------------------ */
    /* Entrée / sortie                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Fait entrer le joueur dans le donjon : rejoint l'instance que son verrou du jour
     * lui réserve, sinon en crée une neuve et pose le verrou.
     *
     * @param User[] $compagnons joueurs à embarquer avec le créateur (lot 2) ; chacun doit
     *                           être libre de verrou pour le jour courant.
     */
    public function entrer(User $user, Donjon $donjon, array $compagnons = []): DonjonInstance
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $donjon, $compagnons): DonjonInstance {
            $existante = $this->instanceVerrouillee($user, $donjon);
            if ($existante !== null) {
                return $this->rejoindre($user, $existante);
            }

            $this->verifierAcces($user, $donjon);
            foreach ($compagnons as $compagnon) {
                $this->verifierAcces($compagnon, $donjon);
                if ($this->instanceVerrouillee($compagnon, $donjon) !== null) {
                    throw new DonjonException(
                        "{$compagnon->getPseudo()} a déjà fait ce donjon aujourd'hui."
                    );
                }
            }

            $effectif = 1 + count($compagnons);
            if ($effectif > $donjon->getTailleGroupeMax()) {
                throw new DonjonException(
                    "Ce donjon accueille au plus {$donjon->getTailleGroupeMax()} joueurs par instance."
                );
            }

            $instance = (new DonjonInstance())
                ->setDonjon($donjon)
                ->setLeader($user);

            if ($donjon->getDureeMaxMinutes() > 0) {
                $instance->setExpireAt(
                    $instance->getCreatedAt()->modify("+{$donjon->getDureeMaxMinutes()} minutes")
                );
            }

            $this->entityManager->persist($instance);

            foreach ([$user, ...$compagnons] as $joueur) {
                $this->ajouterMembre($instance, $joueur);
                $this->poserVerrou($joueur, $donjon, $instance);
            }

            $this->entityManager->flush();

            return $instance;
        });
    }

    /**
     * Le joueur quitte la carte du donjon. L'instance lui reste acquise (son verrou la
     * référence toujours) : il pourra revenir jusqu'au reset. Quand plus personne n'est
     * présent, l'instance est close — ABANDONNEE si le boss n'est pas tombé.
     */
    public function sortir(User $user): void
    {
        $instance = $this->instanceRepository->findInstanceCourante($user);
        if ($instance === null) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $instance): void {
            $verrouillee = $this->entityManager->find(
                DonjonInstance::class,
                $instance->getId(),
                LockMode::PESSIMISTIC_WRITE
            );

            $membre = $verrouillee->membrePour($user);
            if ($membre === null || !$membre->isPresent()) {
                return;
            }

            $membre->setPresent(false);
            $this->entityManager->persist($membre);

            if ($verrouillee->membresPresents() === []) {
                $verrouillee->setStatut(StatutInstanceDonjon::ABANDONNEE);
                $this->entityManager->persist($verrouillee);
            }

            $this->entityManager->flush();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Vie du boss (par instance)                                          */
    /* ------------------------------------------------------------------ */

    /**
     * L'instance du joueur SI le boss visé appartient bien à son donjon — sinon null,
     * et l'appelant retombe sur `boss.actual_life` (boss de plein air).
     */
    public function instancePourBoss(User $user, Boss $boss): ?DonjonInstance
    {
        $instance = $this->instanceRepository->findInstanceCourante($user);
        if ($instance === null) {
            return null;
        }

        $cartesDuBoss = [];
        foreach ($boss->getCarteCarreaus() as $case) {
            $carteId = $case->getCarte()?->getId();
            if ($carteId !== null) {
                $cartesDuBoss[] = $carteId;
            }
        }

        return array_intersect($cartesDuBoss, $instance->getDonjon()->getCarteIds()) !== []
            ? $instance
            : null;
    }

    /** Vie courante du boss dans l'instance (initialisée au max au premier coup). */
    public function vieBoss(DonjonInstance $instance, Boss $boss): int
    {
        return $instance->getBossCurrentLife() ?? $boss->getMaxLife();
    }

    /**
     * Le boss posé sur l'une des cartes du donjon de cette instance.
     *
     * Indispensable dès qu'on veut BLESSER le boss sans passer par une attaque (énigme à
     * leviers) : `bossCurrentLife` vaut null tant qu'il n'a pas été engagé, et retrancher
     * des dégâts à null le tuerait sur-le-champ. Il faut la vie MAX du boss pour partir
     * de la bonne valeur.
     */
    public function bossDeLInstance(DonjonInstance $instance): ?Boss
    {
        $cartes = $instance->getDonjon()?->getCarteIds() ?? [];
        if ($cartes === []) {
            return null;
        }

        // On sélectionne la case (racine) ET le boss joint : le DQL n'autorise pas à
        // sélectionner une entité jointe seule sans son alias racine.
        $case = $this->carteCarreauRepository->createQueryBuilder('cc')
            ->addSelect('boss')
            ->join('cc.boss', 'boss')
            ->where('cc.carte IN (:cartes)')
            ->setParameter('cartes', $cartes)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $case?->getBoss();
    }

    /**
     * Enregistre la vie restante du boss d'instance. À 0, l'instance passe TERMINEE :
     * le groupe garde l'accès à la salle au trésor mais ne peut plus recombattre.
     */
    public function enregistrerVieBoss(DonjonInstance $instance, int $vieRestante): void
    {
        $instance->setBossCurrentLife(max(0, $vieRestante));
        if ($vieRestante <= 0) {
            $instance->setStatut(StatutInstanceDonjon::TERMINEE);
        }
        $this->entityManager->persist($instance);
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function rejoindre(User $user, DonjonInstance $instance): DonjonInstance
    {
        if (!$this->peutRejoindre($instance)) {
            $reset = $this->prochainReset($instance->getDonjon())->format('H\hi');
            throw new DonjonException(
                "Votre expédition a tourné court : le donjon s'est refermé. Revenez après {$reset}."
            );
        }

        $membre = $instance->membrePour($user);
        if ($membre === null) {
            // Verrou pointant une instance dont le joueur n'est pas membre : incohérence
            // (édition manuelle en base ?). On le rattache plutôt que de le bloquer dehors.
            $this->logger->warning('Verrou de donjon sans membre associé', [
                'user' => $user->getId(),
                'instance' => $instance->getId(),
            ]);
            $this->ajouterMembre($instance, $user);
            $this->entityManager->flush();

            return $instance;
        }

        if (!$membre->isPresent()) {
            $membre->setPresent(true);
            // Une instance abandonnée par tout le monde redevient jouable si son
            // occupant revient avant le reset (et si le boss n'est pas déjà tombé).
            if ($instance->getStatut() === StatutInstanceDonjon::ABANDONNEE) {
                $instance->setStatut(StatutInstanceDonjon::EN_COURS);
                $this->entityManager->persist($instance);
            }
            $this->entityManager->persist($membre);
            $this->entityManager->flush();
        }

        return $instance;
    }

    private function verifierAcces(User $user, Donjon $donjon): void
    {
        if (!$donjon->isActif()) {
            throw new DonjonException("Le {$donjon->getNom()} est fermé pour le moment.");
        }

        if ($donjon->getNiveauMin() > 0) {
            $niveau = (int)$this->niveauJoueurRepository->getPlayerLevel($user->getId());
            if ($niveau < $donjon->getNiveauMin()) {
                throw new DonjonException(
                    "{$user->getPseudo()} doit atteindre le niveau {$donjon->getNiveauMin()} pour entrer dans le {$donjon->getNom()}."
                );
            }
        }
    }

    private function ajouterMembre(DonjonInstance $instance, User $user): DonjonInstanceMembre
    {
        $membre = (new DonjonInstanceMembre())
            ->setInstance($instance)
            ->setUser($user);
        $instance->addMembre($membre);
        $this->entityManager->persist($membre);

        return $membre;
    }

    private function poserVerrou(User $user, Donjon $donjon, DonjonInstance $instance): void
    {
        $verrou = (new DonjonVerrou())
            ->setUser($user)
            ->setDonjon($donjon)
            ->setInstance($instance)
            ->setJourReset($this->jourDeDonjon($donjon));

        $this->entityManager->persist($verrou);
    }

    /**
     * Constate l'expiration des instances dont la durée max est dépassée. Les membres
     * encore dedans ne sont PAS téléportés ici : c'est le chargement de carte qui les
     * renverra dehors (DonjonMapView), pour ne pas déplacer un joueur hors de sa requête.
     */
    private function expirerLesInstancesPerimees(): void
    {
        $perimees = $this->instanceRepository->findPerimees(new \DateTimeImmutable());
        if ($perimees === []) {
            return;
        }

        foreach ($perimees as $instance) {
            $instance->setStatut(StatutInstanceDonjon::EXPIREE);
            foreach ($instance->getMembres() as $membre) {
                $membre->setPresent(false);
                $this->entityManager->persist($membre);
            }
            $this->entityManager->persist($instance);
        }

        $this->entityManager->flush();
    }
}
