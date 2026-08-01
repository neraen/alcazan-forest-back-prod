<?php

namespace App\service;

use App\Config\GuildeConfig;
use App\Entity\Guilde;
use App\Entity\JoueurGuilde;
use App\Entity\User;
use App\Enum\GradeGuilde;
use App\Enum\StatutGuilde;
use App\Enum\TypeCible;
use App\Enum\TypeEvenement;
use App\Exception\GuildeException;
use App\Repository\GuildeRepository;
use App\Repository\JoueurGuildeRepository;
use App\Repository\NiveauJoueurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNIQUE machine à états des guildes : personne d'autre n'écrit dans `guilde` ni dans
 * `joueur_guilde`.
 *
 * Ce service OUVRE ses transactions, contrairement à `SacService`, `KarmaService` ou
 * `CumulJoueurService`. La règle « ne flushe pas » vise les services de VALEUR, qu'un
 * appelant compose ; une machine à états est l'appelant — même contrat que `EchangeService`,
 * `HotelVenteService` et `DonjonInstanceService`.
 *
 * ## Le bug qu'il corrige
 *
 * `user.guilde_id` et `joueur_guilde` coexistaient. L'adhésion écrivait dans la seconde,
 * TOUT l'affichage lisait la première, et aucun code n'écrivait jamais la première :
 * rejoindre une guilde n'avait donc strictement aucun effet visible. La colonne est
 * supprimée, `joueur_guilde` devient la seule vérité.
 *
 * ## Les règles, et pourquoi elles sont là
 *
 * - **Une ligne par joueur** (index UNIQUE) : candidat quelque part OU membre quelque part.
 * - **L'alignement doit correspondre** : c'est la seule règle qui donne aujourd'hui une
 *   conséquence de jeu à `user.alignement`.
 * - **`placeMax` est vérifié à l'ACCEPTATION et pas à la candidature** : une guilde pleine
 *   peut recevoir des candidatures, elles attendront qu'une place se libère. Bloquer en
 *   amont obligerait le candidat à surveiller la guilde pour retenter.
 * - **Le baron ne peut pas partir sans transmettre ou dissoudre** : sinon la guilde reste
 *   avec des candidatures que plus personne ne peut accepter, ni dissoudre.
 */
class GuildeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GuildeRepository $guildeRepository,
        private readonly JoueurGuildeRepository $joueurGuildeRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        private readonly SacService $sacService,
        private readonly JournalService $journalService,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Fondation                                                           */
    /* ------------------------------------------------------------------ */

    /** @throws GuildeException */
    public function creer(User $user, string $nom, ?string $description): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $nom, $description): array {
            $this->refuserSiDejaEngage($user);

            $alignement = $user->getAlignement();
            if ($alignement === null) {
                throw new GuildeException("Vous devez choisir un alignement avant de fonder une guilde.");
            }

            $niveau = (int) $this->niveauJoueurRepository->getPlayerLevel($user->getId());
            if ($niveau < GuildeConfig::NIVEAU_MIN_CREATION) {
                throw new GuildeException(sprintf(
                    "Il faut être niveau %d pour fonder une guilde (vous êtes niveau %d).",
                    GuildeConfig::NIVEAU_MIN_CREATION,
                    $niveau
                ));
            }

            $nom = trim($nom);
            $longueur = mb_strlen($nom);
            if ($longueur < GuildeConfig::NOM_MIN || $longueur > GuildeConfig::NOM_MAX) {
                throw new GuildeException(sprintf(
                    "Le nom doit faire entre %d et %d caractères.",
                    GuildeConfig::NOM_MIN,
                    GuildeConfig::NOM_MAX
                ));
            }
            if ($this->guildeRepository->findOneBy(['nom' => $nom]) !== null) {
                throw new GuildeException("Une guilde porte déjà ce nom.");
            }

            $description = $description === null ? null : mb_substr(trim($description), 0, GuildeConfig::DESCRIPTION_MAX);

            // Le coût est prélevé AVANT la création : `debiterOr` lève si la bourse ne suit
            // pas, et la transaction annule tout — jamais de guilde impayée.
            $this->sacService->debiterOr($user, GuildeConfig::COUT_CREATION);

            $guilde = (new Guilde())
                ->setNom($nom)
                ->setDescription($description)
                ->setAlignement($alignement)
                ->setPlaceMax(GuildeConfig::PLACE_MAX_DEFAUT)
                ->setNiveau(0)
                ->setIcone('')
                ->setBanner('');

            $this->entityManager->persist($guilde);

            $appartenance = (new JoueurGuilde())
                ->setUser($user)
                ->setGuilde($guilde)
                ->setGrade(GradeGuilde::BARON)
                ->setStatut(StatutGuilde::MEMBRE)
                ->setRejointLe(new \DateTimeImmutable());

            $this->entityManager->persist($appartenance);
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_CREEE,
                acteur: $user,
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $guilde->getId(),
                montantOr: GuildeConfig::COUT_CREATION,
                contexte: ['nom' => $guilde->getNom()],
            );

            return $this->etat($user);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Candidature                                                         */
    /* ------------------------------------------------------------------ */

    /** @throws GuildeException */
    public function candidater(User $user, int $guildeId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $guildeId): array {
            $this->refuserSiDejaEngage($user);

            $guilde = $this->guildeRepository->find($guildeId);
            if ($guilde === null) {
                throw new GuildeException("Cette guilde n'existe pas.");
            }

            $alignement = $user->getAlignement();
            if ($alignement === null) {
                throw new GuildeException("Vous devez choisir un alignement avant de rejoindre une guilde.");
            }
            if ($guilde->getAlignement()?->getId() !== $alignement->getId()) {
                throw new GuildeException("Cette guilde n'est pas de votre alignement.");
            }

            $appartenance = (new JoueurGuilde())
                ->setUser($user)
                ->setGuilde($guilde)
                ->setGrade(GradeGuilde::RECRUE)
                ->setStatut(StatutGuilde::CANDIDAT);

            $this->entityManager->persist($appartenance);
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_CANDIDATURE,
                acteur: $user,
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $guilde->getId(),
                contexte: ['nom' => $guilde->getNom()],
            );

            return $this->etat($user);
        });
    }

    /** @throws GuildeException */
    public function accepter(User $decideur, int $candidatUserId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($decideur, $candidatUserId): array {
            [$moi, $candidature] = $this->decideurEtCible($decideur, $candidatUserId);

            if (!$moi->getGrade()->peutAccepter()) {
                throw new GuildeException("Votre grade ne vous permet pas d'accepter une candidature.");
            }
            if ($candidature->getStatut() !== StatutGuilde::CANDIDAT) {
                throw new GuildeException("Ce joueur est déjà membre de la guilde.");
            }

            $guilde = $moi->getGuilde();
            // Vérifié ICI et non à la candidature : une guilde pleine peut recevoir des
            // candidatures, qui attendront qu'une place se libère.
            if ($this->joueurGuildeRepository->compterMembres($guilde) >= $guilde->getPlaceMax()) {
                throw new GuildeException("La guilde est complète.");
            }

            $candidature->setStatut(StatutGuilde::MEMBRE);
            $candidature->setGrade(GradeGuilde::RECRUE);
            $candidature->setRejointLe(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_ACCEPTATION,
                acteur: $decideur,
                cibleUser: $candidature->getUser(),
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $guilde->getId(),
                contexte: ['nom' => $guilde->getNom()],
            );

            return $this->etat($decideur);
        });
    }

    /** @throws GuildeException */
    public function refuser(User $decideur, int $candidatUserId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($decideur, $candidatUserId): array {
            [$moi, $candidature] = $this->decideurEtCible($decideur, $candidatUserId);

            if (!$moi->getGrade()->peutAccepter()) {
                throw new GuildeException("Votre grade ne vous permet pas de refuser une candidature.");
            }
            if ($candidature->getStatut() !== StatutGuilde::CANDIDAT) {
                throw new GuildeException("Ce joueur est membre : excluez-le plutôt que de le refuser.");
            }

            $guilde = $moi->getGuilde();
            $candidat = $candidature->getUser();
            $this->entityManager->remove($candidature);
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_REFUS,
                acteur: $decideur,
                cibleUser: $candidat,
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $guilde->getId(),
                contexte: ['nom' => $guilde->getNom()],
            );

            return $this->etat($decideur);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Vie de la guilde                                                    */
    /* ------------------------------------------------------------------ */

    /** @throws GuildeException */
    public function promouvoir(User $decideur, int $membreUserId, GradeGuilde $grade): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($decideur, $membreUserId, $grade): array {
            [$moi, $cible] = $this->decideurEtCible($decideur, $membreUserId);

            if (!$moi->getGrade()->peutPromouvoir()) {
                throw new GuildeException("Seul le baron peut changer les grades.");
            }
            if (!$cible->estMembre()) {
                throw new GuildeException("Ce joueur n'est encore qu'un candidat.");
            }
            if ($cible->getUser()->getId() === $decideur->getId()) {
                throw new GuildeException("Vous ne pouvez pas changer votre propre grade. Transmettez la baronnie.");
            }
            if (!in_array($grade, GradeGuilde::attribuables(), true)) {
                throw new GuildeException("Ce grade ne peut pas être attribué. Utilisez la transmission de baronnie.");
            }

            $cible->setGrade($grade);
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_GRADE,
                acteur: $decideur,
                cibleUser: $cible->getUser(),
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $moi->getGuilde()->getId(),
                contexte: ['grade' => $grade->value, 'nom' => $moi->getGuilde()->getNom()],
            );

            return $this->etat($decideur);
        });
    }

    /**
     * Transmet la baronnie : l'ancien baron devient officier.
     *
     * Une opération à part et non un cas de `promouvoir()`, parce qu'elle touche DEUX lignes
     * et qu'il ne doit jamais y avoir deux barons — ni zéro.
     *
     * @throws GuildeException
     */
    public function transmettre(User $baron, int $membreUserId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($baron, $membreUserId): array {
            [$moi, $cible] = $this->decideurEtCible($baron, $membreUserId);

            if ($moi->getGrade() !== GradeGuilde::BARON) {
                throw new GuildeException("Seul le baron peut transmettre la baronnie.");
            }
            if (!$cible->estMembre()) {
                throw new GuildeException("Ce joueur n'est encore qu'un candidat.");
            }

            $cible->setGrade(GradeGuilde::BARON);
            $moi->setGrade(GradeGuilde::OFFICIER);
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_GRADE,
                acteur: $baron,
                cibleUser: $cible->getUser(),
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $moi->getGuilde()->getId(),
                contexte: ['grade' => GradeGuilde::BARON->value, 'transmission' => true],
            );

            return $this->etat($baron);
        });
    }

    /** @throws GuildeException */
    public function exclure(User $decideur, int $membreUserId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($decideur, $membreUserId): array {
            [$moi, $cible] = $this->decideurEtCible($decideur, $membreUserId);

            if ($cible->getUser()->getId() === $decideur->getId()) {
                throw new GuildeException("Pour partir, quittez la guilde.");
            }
            if (!$moi->getGrade()->peutExclure($cible->getGrade())) {
                throw new GuildeException("Votre grade ne vous permet pas d'exclure ce joueur.");
            }

            $guilde = $moi->getGuilde();
            $exclu = $cible->getUser();
            $this->entityManager->remove($cible);
            $this->entityManager->flush();

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_EXCLUSION,
                acteur: $decideur,
                cibleUser: $exclu,
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $guilde->getId(),
                contexte: ['nom' => $guilde->getNom()],
            );

            return $this->etat($decideur);
        });
    }

    /** @throws GuildeException */
    public function quitter(User $user): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user): array {
            $appartenance = $this->joueurGuildeRepository->pourJoueur($user);
            if ($appartenance === null) {
                throw new GuildeException("Vous n'appartenez à aucune guilde.");
            }

            $guilde = $appartenance->getGuilde();
            $etaitCandidat = !$appartenance->estMembre();

            if ($appartenance->getGrade() === GradeGuilde::BARON
                && $this->joueurGuildeRepository->compterMembres($guilde) > 1) {
                throw new GuildeException(
                    "Un baron ne peut pas abandonner sa guilde : transmettez la baronnie, ou dissolvez-la."
                );
            }

            $this->entityManager->remove($appartenance);
            $this->entityManager->flush();

            // Le dernier membre qui part emporte la guilde avec lui : une guilde à zéro
            // membre serait inaccessible pour toujours et polluerait l'annuaire.
            if (!$etaitCandidat && $this->joueurGuildeRepository->compterMembres($guilde) === 0) {
                return $this->dissoudreEffectivement($user, $guilde);
            }

            $this->journalService->consigner(
                type: TypeEvenement::GUILDE_DEPART,
                acteur: $user,
                cibleType: TypeCible::GUILDE,
                cibleId: (int) $guilde->getId(),
                contexte: ['nom' => $guilde->getNom(), 'candidature' => $etaitCandidat],
            );

            return $this->etat($user);
        });
    }

    /** @throws GuildeException */
    public function dissoudre(User $baron): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($baron): array {
            $appartenance = $this->joueurGuildeRepository->pourJoueur($baron);
            if ($appartenance === null || !$appartenance->estMembre()) {
                throw new GuildeException("Vous n'appartenez à aucune guilde.");
            }
            if (!$appartenance->getGrade()->peutDissoudre()) {
                throw new GuildeException("Seul le baron peut dissoudre la guilde.");
            }

            return $this->dissoudreEffectivement($baron, $appartenance->getGuilde());
        });
    }

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /** L'état complet vu par ce joueur : sa guilde, ses membres, ses candidatures. */
    public function etat(User $user): array
    {
        $appartenance = $this->joueurGuildeRepository->pourJoueur($user);

        if ($appartenance === null) {
            return [
                'appartenance' => null,
                'guilde' => null,
                'membres' => [],
                'candidatures' => [],
                'config' => GuildeConfig::pourLeFront(),
                'grades' => self::gradesPourLeFront(),
            ];
        }

        $guilde = $appartenance->getGuilde();
        $lignes = $this->joueurGuildeRepository->pourGuilde($guilde);
        $peutGerer = $appartenance->estMembre() && $appartenance->getGrade()->peutAccepter();

        $membres = [];
        $candidatures = [];
        foreach ($lignes as $ligne) {
            $decrite = $this->decrireLigne($ligne);
            if ($ligne->estMembre()) {
                $membres[] = $decrite;
            } elseif ($peutGerer) {
                // Les candidatures ne sont montrées qu'à ceux qui peuvent les traiter : un
                // candidat n'a pas à connaître la liste de ses concurrents.
                $candidatures[] = $decrite;
            }
        }

        return [
            'appartenance' => [
                'statut' => $appartenance->getStatut()->value,
                'statutLabel' => $appartenance->getStatut()->label(),
                'grade' => $appartenance->getGrade()->value,
                'gradeLabel' => $appartenance->getGrade()->label(),
                'peutGerer' => $peutGerer,
                'peutPromouvoir' => $appartenance->estMembre() && $appartenance->getGrade()->peutPromouvoir(),
                'peutDissoudre' => $appartenance->estMembre() && $appartenance->getGrade()->peutDissoudre(),
            ],
            'guilde' => $this->decrireGuilde($guilde, count($membres)),
            'membres' => $membres,
            'candidatures' => $candidatures,
            'config' => GuildeConfig::pourLeFront(),
            'grades' => self::gradesPourLeFront(),
        ];
    }

    /**
     * Les guildes que ce joueur peut rejoindre : celles de son alignement.
     *
     * L'alignement est la seule règle qui donne aujourd'hui une conséquence de jeu au champ
     * `user.alignement` — sans elle, il ne serait qu'un badge.
     */
    public function annuaire(User $user): array
    {
        $alignement = $user->getAlignement();
        if ($alignement === null) {
            return [
                'guildes' => [],
                'message' => "Choisissez un alignement pour voir les guildes qui vous sont ouvertes.",
                'config' => GuildeConfig::pourLeFront(),
            ];
        }

        $guildes = $this->guildeRepository->findBy(['alignement' => $alignement], ['nom' => 'ASC']);
        $membres = $this->joueurGuildeRepository->compterMembresParGuilde(
            array_map(static fn (Guilde $guilde) => (int) $guilde->getId(), $guildes)
        );

        return [
            'guildes' => array_map(
                fn (Guilde $guilde) => $this->decrireGuilde($guilde, $membres[(int) $guilde->getId()] ?? 0),
                $guildes
            ),
            'message' => null,
            'config' => GuildeConfig::pourLeFront(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function dissoudreEffectivement(User $acteur, Guilde $guilde): array
    {
        $nom = $guilde->getNom();
        $guildeId = (int) $guilde->getId();

        foreach ($this->joueurGuildeRepository->pourGuilde($guilde) as $ligne) {
            $this->entityManager->remove($ligne);
        }
        $this->entityManager->remove($guilde);
        $this->entityManager->flush();

        $this->journalService->consigner(
            type: TypeEvenement::GUILDE_DISSOUTE,
            acteur: $acteur,
            cibleType: TypeCible::GUILDE,
            cibleId: $guildeId,
            contexte: ['nom' => $nom],
        );

        return $this->etat($acteur);
    }

    /**
     * Le décideur et sa cible, tous deux dans LA MÊME guilde.
     *
     * Centralisé parce que c'est le garde-fou que chaque transition doit poser et qu'aucune
     * ne doit oublier : sans lui, un baron pourrait agir sur les membres d'une autre guilde.
     *
     * @return array{0: JoueurGuilde, 1: JoueurGuilde}
     * @throws GuildeException
     */
    private function decideurEtCible(User $decideur, int $cibleUserId): array
    {
        $moi = $this->joueurGuildeRepository->pourJoueur($decideur);
        if ($moi === null || !$moi->estMembre()) {
            throw new GuildeException("Vous n'appartenez à aucune guilde.");
        }

        $cible = $this->joueurGuildeRepository->findOneBy([
            'user' => $cibleUserId,
            'guilde' => $moi->getGuilde(),
        ]);
        if ($cible === null) {
            throw new GuildeException("Ce joueur n'est pas dans votre guilde.");
        }

        return [$moi, $cible];
    }

    /** @throws GuildeException */
    private function refuserSiDejaEngage(User $user): void
    {
        $existante = $this->joueurGuildeRepository->pourJoueur($user);
        if ($existante === null) {
            return;
        }

        throw new GuildeException($existante->estMembre()
            ? "Vous appartenez déjà à une guilde. Quittez-la d'abord."
            : "Vous avez déjà une candidature en cours. Annulez-la d'abord.");
    }

    private function decrireGuilde(Guilde $guilde, int $membres): array
    {
        return [
            'id' => (int) $guilde->getId(),
            'nom' => $guilde->getNom(),
            'description' => $guilde->getDescription(),
            'niveau' => $guilde->getNiveau(),
            'icone' => $guilde->getIcone(),
            'placeMax' => $guilde->getPlaceMax(),
            'membres' => $membres,
            'complete' => $membres >= $guilde->getPlaceMax(),
            'alignement' => $guilde->getAlignement()?->getNom(),
        ];
    }

    private function decrireLigne(JoueurGuilde $ligne): array
    {
        $user = $ligne->getUser();

        return [
            'userId' => (int) $user->getId(),
            'pseudo' => $user->getPseudo(),
            'classe' => $user->getClasse()?->getNom(),
            'niveau' => $user->getNiveauJoueur()?->getNiveau()?->getNiveau(),
            'grade' => $ligne->getGrade()->value,
            'gradeLabel' => $ligne->getGrade()->label(),
            'statut' => $ligne->getStatut()->value,
            'rejointLe' => $ligne->getRejointLe()?->format('Y-m-d'),
        ];
    }

    /** Le front ne connaît aucun grade en dur : ils descendent d'ici. */
    private static function gradesPourLeFront(): array
    {
        return array_map(
            static fn (GradeGuilde $grade) => [
                'valeur' => $grade->value,
                'label' => $grade->label(),
                'attribuable' => in_array($grade, GradeGuilde::attribuables(), true),
            ],
            GradeGuilde::cases()
        );
    }
}
