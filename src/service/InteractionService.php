<?php

namespace App\service;

use App\Entity\CarteCarreau;
use App\Entity\Interaction;
use App\Entity\InteractionCondition;
use App\Entity\InteractionRecharge;
use App\Entity\User;
use App\Config\RecolteConfig;
use App\Enum\ModeRecolte;
use App\Enum\PorteeRecharge;
use App\Enum\TypeCompteur;
use App\Enum\TypeConditionInteraction;
use App\Enum\TypeInteraction;
use App\Enum\TypeItem;
use App\Exception\InteractionException;
use App\Repository\CarteCarreauRepository;
use App\Repository\InteractionRechargeRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\UserQueteRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine à états des cases interactives : conditions, coût en PA, récompense,
 * effet scripté, gain de métier et rechargement. Aucun contrôleur n'écrit dans
 * `interaction_recharge`.
 *
 * Trois principes :
 *
 *  1. **Le client ne décide de rien.** Il envoie l'id de la case ; le serveur revérifie
 *     l'adjacence, les conditions, les PA et la disponibilité. `decrire()` n'existe que
 *     pour l'affichage — ce qu'il renvoie n'autorise jamais rien.
 *
 *  2. **La portée du cooldown est la clé.** Un même mécanisme couvre l'herbe que chacun
 *     récolte de son côté (JOUEUR), le coffre que le premier arrivé vide pour tous
 *     (MONDE) et le coffre propre à une expédition (INSTANCE).
 *
 *     S'y ajoute, depuis le lot 2 de l'artisanat, une SECONDE recharge sur les cases qui
 *     proposent le choix de récolte : l'ÉPUISEMENT du gisement, toujours partagé, lu en
 *     plus du cooldown personnel. C'est elle, et elle seule, qui permet à une récolte
 *     intensive de léser autrui — avec la seule portée JOUEUR, chacun ayant son propre
 *     délai, ce que fait A ne peut par construction pas atteindre B.
 *
 *  3. **Rien n'est redistribué ici.** Les items et l'or passent par RecompenseService,
 *     l'expérience de métier par MetierService, les effets scriptés par
 *     QuestEffectRegistry. Ce service orchestre, il ne duplique pas.
 */
class InteractionService
{
    public function __construct(
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly InteractionRechargeRepository $rechargeRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        private readonly UserQueteRepository $userQueteRepository,
        private readonly RecompenseService $recompenseService,
        private readonly MetierService $metierService,
        private readonly KarmaService $karmaService,
        private readonly CompteurJoueurService $compteurJoueurService,
        private readonly SacService $sacService,
        private readonly QuestEffectRegistry $effectRegistry,
        private readonly DonjonInstanceService $donjonInstanceService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Ce que le joueur doit voir sur la case : nom, verbe, disponibilité et — s'il ne
     * peut pas agir — la raison. Purement INFORMATIF : ce qu'on renvoie ici n'autorise
     * jamais rien, `executer()` revérifie tout.
     *
     * `disponibleAt` est une date SERVEUR : le front recalcule le compte à rebours à
     * partir d'elle plutôt que de décompter un nombre de secondes reçu, qui dériverait
     * dans un onglet en arrière-plan.
     */
    public function decrire(
        User $user,
        CarteCarreau $case,
        ?string $clePortee = null,
        ?string $cleEpuisement = null
    ): ?array {
        $interaction = $case->getInteraction();
        if ($interaction === null || !$interaction->isActif()) {
            return null;
        }

        $recharge = $this->rechargeRepository->findOneBy([
            'carteCarreau' => $case,
            'cle' => $clePortee ?? $this->cleDePortee($user, $interaction),
        ]);

        // L'épuisement partagé n'existe que sur les cases qui proposent le choix : inutile
        // d'aller le chercher pour un levier ou un coffre.
        $epuisement = $interaction->isRecolteChoix()
            ? $this->rechargeRepository->findOneBy([
                'carteCarreau' => $case,
                'cle' => $cleEpuisement ?? $this->cleEpuisement($user, $interaction),
            ])
            : null;

        $blocage = $this->blocageLePlusTardif($recharge, $epuisement);
        $raison = $blocage['raison'] ?? $this->premierRefus($user, $interaction);

        return [
            'carteCarreauId' => $case->getId(),
            'nom' => $interaction->getNom(),
            'verbe' => $interaction->getType()->verbe(),
            'type' => $interaction->getType()->value,
            'skin' => $interaction->getSkin(),
            'coutPa' => $interaction->getCoutPa(),
            'recolteChoix' => $interaction->isRecolteChoix(),
            'disponible' => $raison === null,
            'raison' => $raison,
            'disponibleAt' => $blocage['disponibleAt'],
            'epuisee' => $blocage['epuisee'],
            // Distingué du cooldown personnel : « j'ai récolté trop tôt » et « quelqu'un a
            // saigné le filon » n'appellent ni le même repère à l'écran ni la même réaction.
            'gisementEpuise' => $blocage['gisement'],
        ];
    }

    /**
     * Des deux verrous possibles (cooldown personnel, épuisement partagé), celui qui se
     * lève EN DERNIER — c'est lui qui décide réellement de la disponibilité. Prendre le
     * premier venu afficherait un compte à rebours plus court que la réalité.
     *
     * @return array{raison: ?string, disponibleAt: ?string, epuisee: bool, gisement: bool}
     */
    private function blocageLePlusTardif(?InteractionRecharge $recharge, ?InteractionRecharge $epuisement): array
    {
        $aucun = ['raison' => null, 'disponibleAt' => null, 'epuisee' => false, 'gisement' => false];
        $candidats = [];

        if ($recharge !== null && !$recharge->estDisponible()) {
            $definitif = $recharge->getDisponibleAt() === null;
            $candidats[] = [
                'raison' => $definitif
                    ? "Cet endroit a déjà livré ce qu'il avait."
                    : "Il faut attendre avant de recommencer.",
                'disponibleAt' => $definitif ? null : $recharge->getDisponibleAt()->format(\DateTimeInterface::ATOM),
                'epuisee' => $definitif,
                'gisement' => false,
                'fin' => $recharge->getDisponibleAt(),
            ];
        }

        if ($epuisement !== null && !$epuisement->estDisponible()) {
            $definitif = $epuisement->getDisponibleAt() === null;
            $candidats[] = [
                'raison' => "Le gisement a été saigné : il faut le laisser se refaire.",
                'disponibleAt' => $definitif ? null : $epuisement->getDisponibleAt()->format(\DateTimeInterface::ATOM),
                'epuisee' => $definitif,
                'gisement' => true,
                'fin' => $epuisement->getDisponibleAt(),
            ];
        }

        if ($candidats === []) {
            return $aucun;
        }

        // `fin` à null = jamais rechargé : c'est le blocage le plus fort qui soit.
        usort($candidats, function (array $a, array $b): int {
            if ($a['fin'] === null || $b['fin'] === null) {
                return ($b['fin'] === null ? 1 : 0) - ($a['fin'] === null ? 1 : 0);
            }

            return $b['fin'] <=> $a['fin'];
        });

        $retenu = $candidats[0];
        unset($retenu['fin']);

        return $retenu;
    }

    /**
     * État de TOUTES les cases interactives d'une carte, en un passage.
     *
     * La clé de portée « instance » est calculée UNE fois : sinon chaque case relancerait
     * la recherche d'instance courante (et son expiration paresseuse) au chargement de
     * carte.
     *
     * @param array $cases lignes de getAllCasesOfMap
     * @return array<int, array> carteCarreauId => état
     */
    public function decrireCases(User $user, array $cases): array
    {
        $ids = [];
        foreach ($cases as $case) {
            if (!empty($case['interactionId'])) {
                $ids[] = $case['carteCarreauId'];
            }
        }
        if ($ids === []) {
            return [];
        }

        $instanceId = $this->donjonInstanceService->instanceCourante($user)?->getId() ?? 0;
        $etats = [];

        foreach ($this->carteCarreauRepository->findBy(['id' => $ids]) as $case) {
            $interaction = $case->getInteraction();
            if ($interaction === null) {
                continue;
            }

            $cle = match ($interaction->getPorteeRecharge()) {
                PorteeRecharge::MONDE => 'monde',
                PorteeRecharge::JOUEUR => 'user:' . $user->getId(),
                PorteeRecharge::INSTANCE => 'instance:' . $instanceId,
            };

            $etat = $this->decrire($user, $case, $cle, self::cleEpuisementPour($interaction, $instanceId));
            if ($etat !== null) {
                $etats[$case->getId()] = $etat;
            }
        }

        return $etats;
    }

    /* ------------------------------------------------------------------ */
    /* Exécution                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param ?ModeRecolte $mode manière de prélever, sur les seules cases qui proposent
     *        le choix. Un mode envoyé ailleurs est refusé.
     * @return array{messages: string[], rewards: array, metier: ?array, needRefresh: bool}
     */
    public function executer(User $user, int $carteCarreauId, ?ModeRecolte $mode = null): array
    {
        $case = $this->carteCarreauRepository->find($carteCarreauId);
        if ($case === null || $case->getInteraction() === null) {
            throw new InteractionException("Il n'y a rien à faire ici.");
        }

        $interaction = $case->getInteraction();
        if (!$interaction->isActif()) {
            throw new InteractionException("Cet endroit est inerte.");
        }

        if ($mode !== null && !$interaction->isRecolteChoix()) {
            throw new InteractionException("On ne récolte pas cet endroit de cette manière.");
        }

        // Une case qui propose le choix mais qu'on sollicite sans mode (vieux client,
        // appel direct) est traitée en récolte MESURÉE : dans le doute, on ne suppose
        // jamais que le joueur voulait raser le gisement.
        if ($interaction->isRecolteChoix() && $mode === null) {
            $mode = ModeRecolte::ETHIQUE;
        }

        $this->verifierProximite($user, $case);

        $refus = $this->premierRefus($user, $interaction);
        if ($refus !== null) {
            throw new InteractionException($refus);
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $case, $interaction, $mode): array {
            // Verrou pessimiste sur la case : sans lui, deux joueurs peuvent vider le même
            // coffre de portée MONDE en même temps — ou saigner le même gisement.
            $this->entityManager->find(CarteCarreau::class, $case->getId(), LockMode::PESSIMISTIC_WRITE);

            $recharge = $this->rechargeCourante($user, $case, $interaction);
            if ($recharge !== null && !$recharge->estDisponible()) {
                throw new InteractionException(
                    $recharge->getDisponibleAt() === null
                        ? "Cet endroit a déjà livré ce qu'il avait."
                        : "Il faut attendre avant de recommencer."
                );
            }

            // Le gisement épuisé bloque TOUT LE MONDE, y compris celui qui l'a saigné.
            $epuisement = $this->epuisementCourant($user, $case, $interaction);
            if ($epuisement !== null && !$epuisement->estDisponible()) {
                throw new InteractionException("Le gisement a été saigné : il faut le laisser se refaire.");
            }

            if ($interaction->getCoutPa() > 0) {
                if ($user->getActionPoint() < $interaction->getCoutPa()) {
                    throw new InteractionException("Vous n'avez pas assez de points d'action.");
                }
                $user->setActionPoint($user->getActionPoint() - $interaction->getCoutPa());
                $this->entityManager->persist($user);
            }

            $messages = [];
            $metier = null;
            $karma = null;

            $multiplicateur = $mode !== null ? RecolteConfig::multiplicateurQuantite($mode) : 1;
            ['rewards' => $rewards] = $this->recompenseService->distribuer(
                $user,
                $interaction->getRecompense(),
                $multiplicateur
            );
            if ($rewards !== []) {
                $messages = array_merge($messages, $this->recompenseService->decrireRecompenses($rewards));
            }

            $this->compterRessourcesRecoltees($user, $interaction, $rewards);

            if ($interaction->exigeUnMetier() && $interaction->getExperienceMetier() > 0) {
                $metier = $this->metierService->gagnerExperience(
                    $user,
                    $interaction->getMetier(),
                    $interaction->getExperienceMetier()
                );
                $messages[] = "{$interaction->getMetier()->getNom()} : +{$interaction->getExperienceMetier()} points d'expérience.";
                if ($metier['niveauxGagnes'] > 0) {
                    $messages[] = "Vous atteignez le niveau {$metier['niveau']} en {$interaction->getMetier()->getNom()} !";
                }
            }

            $needRefresh = false;
            if ($interaction->getEffect() !== null) {
                $params = ($interaction->getEffectParams() ?? []) + ['carteCarreauId' => $case->getId()];
                $resultat = $this->effectRegistry->execute($interaction->getEffect(), $params, $user);
                $messages = array_merge($messages, $resultat['messages']);
                $needRefresh = $resultat['needRefresh'];
            }

            // L'XP de métier ne dépend PAS du mode : elle vient du geste, pas du butin.
            // La faire suivre le rendement ferait de l'intensif un choix doublement
            // gagnant, alors qu'il est censé être un arbitrage.
            if ($mode !== null) {
                $ajustement = $this->karmaService->ajuster($user, RecolteConfig::karma($mode));
                // `delta` vaut 0 quand la borne était déjà atteinte : on n'annonce alors
                // rien plutôt qu'un changement qui n'a pas eu lieu.
                if ($ajustement['delta'] !== 0) {
                    $karma = $ajustement;
                    $messages[] = sprintf(
                        $ajustement['delta'] > 0
                            ? "Vous avez prélevé avec mesure (karma %+d)."
                            : "Vous avez saigné l'endroit (karma %+d).",
                        $ajustement['delta']
                    );
                }
            }

            if ($interaction->getMessageSucces() !== null) {
                array_unshift($messages, $interaction->getMessageSucces());
            }

            $this->poserRecharges($user, $case, $interaction, $mode);
            $this->entityManager->flush();

            return [
                'messages' => $messages === [] ? ["Il ne se passe rien."] : $messages,
                'rewards' => $rewards,
                'metier' => $metier,
                'karma' => $karma,
                'pa' => $user->getActionPoint(),
                // État frais de la case : le front met à jour le repère (grisé + compte à
                // rebours) sans recharger toute la carte.
                'etat' => $this->decrire($user, $case),
                'needRefresh' => $needRefresh || $rewards !== [],
            ];
        });
    }

    /**
     * Compte les ressources ramassées, pour les actions de quête RECOLTER_RESSOURCE.
     *
     * Restreint aux interactions de type RÉCOLTER : un coffre livre lui aussi des objets,
     * mais l'ouvrir n'est pas récolter, et une quête de cueilleur ne doit pas se valider
     * en pillant une réserve. Le type est là pour qualifier le geste — c'est exactement
     * l'usage prévu.
     *
     * On compte ce qui est RÉELLEMENT tombé dans le sac (quantité déjà multipliée par le
     * mode) : récolter intensivement compte triple, comme ça rapporte triple. Les
     * équipements et consommables ne sont pas comptés — une ressource est un `objet`.
     *
     * @param list<array{type: string, id: ?int, quantity: int}> $rewards
     */
    private function compterRessourcesRecoltees(User $user, Interaction $interaction, array $rewards): void
    {
        if ($interaction->getType() !== TypeInteraction::RECOLTER) {
            return;
        }

        foreach ($rewards as $reward) {
            if ($reward['type'] !== 'objet' || $reward['id'] === null) {
                continue;
            }

            $this->compteurJoueurService->incrementer(
                $user,
                TypeCompteur::RESSOURCE_RECOLTEE,
                (int)$reward['id'],
                (int)$reward['quantity']
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Conditions                                                          */
    /* ------------------------------------------------------------------ */

    /** Première condition non remplie, en message joueur — ou null si tout passe. */
    private function premierRefus(User $user, Interaction $interaction): ?string
    {
        if ($interaction->exigeUnMetier()) {
            $metier = $interaction->getMetier();
            $niveau = $this->metierService->niveau($user, $metier);
            $requis = max(1, $interaction->getNiveauMetierMin());
            if ($niveau < $requis) {
                return $niveau === 0
                    ? "Il faut être {$metier->getNom()} pour cela."
                    : "Il faut être {$metier->getNom()} niveau {$requis} (vous êtes niveau {$niveau}).";
            }
        }

        foreach ($interaction->getConditions() as $condition) {
            $refus = $this->refusDeCondition($user, $condition);
            if ($refus !== null) {
                return $refus;
            }
        }

        return null;
    }

    private function refusDeCondition(User $user, InteractionCondition $condition): ?string
    {
        return match ($condition->getType()) {
            TypeConditionInteraction::NIVEAU => $this->refusNiveau($user, (int)$condition->param('niveau')),
            TypeConditionInteraction::CLASSE => $user->getClasse()?->getId() === (int)$condition->param('classeId')
                ? null : "Votre classe ne peut pas faire cela.",
            TypeConditionInteraction::ALIGNEMENT => $user->getAlignement()?->getId() === (int)$condition->param('alignementId')
                ? null : "Votre alignement vous l'interdit.",
            TypeConditionInteraction::QUETE_TERMINEE => $this->refusQuete($user, (int)$condition->param('queteId')),
            TypeConditionInteraction::POSSEDE_OBJET => $this->refusObjet(
                $user,
                (int)$condition->param('objetId'),
                max(1, (int)$condition->param('quantite'))
            ),
        };
    }

    private function refusNiveau(User $user, int $requis): ?string
    {
        $niveau = (int)$this->niveauJoueurRepository->getPlayerLevel($user->getId());

        return $niveau >= $requis ? null : "Il faut être niveau {$requis} (vous êtes niveau {$niveau}).";
    }

    private function refusQuete(User $user, int $queteId): ?string
    {
        $userQuete = $this->userQueteRepository->findOneBy(['user' => $user, 'quete' => $queteId]);

        return $userQuete !== null && $userQuete->getIsDone()
            ? null
            : "Vous n'avez pas encore accompli ce qu'il faut pour cela.";
    }

    private function refusObjet(User $user, int $objetId, int $quantite): ?string
    {
        // « Disponible » et non « possédé » : un objet réservé dans un échange en cours
        // ne doit pas servir de sésame.
        return $this->sacService->quantiteDisponible($user, TypeItem::OBJET, $objetId) >= $quantite
            ? null
            : "Il vous manque de quoi faire cela.";
    }

    /* ------------------------------------------------------------------ */
    /* Rechargement                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Clé de portée. Une chaîne plutôt que des colonnes nullables : en MySQL, un index
     * UNIQUE laisse passer les doublons dès qu'une colonne vaut NULL, ce qui aurait permis
     * deux recharges « monde » concurrentes sur la même case.
     */
    private function cleDePortee(User $user, Interaction $interaction): string
    {
        return match ($interaction->getPorteeRecharge()) {
            PorteeRecharge::MONDE => 'monde',
            PorteeRecharge::JOUEUR => 'user:' . $user->getId(),
            PorteeRecharge::INSTANCE => 'instance:' . ($this->donjonInstanceService->instanceCourante($user)?->getId() ?? 0),
        };
    }

    /**
     * Clé de l'ÉPUISEMENT du gisement. Toujours partagée — c'est sa raison d'être — mais
     * bornée à un monde : deux expéditions de donjon ne doivent pas se saigner
     * mutuellement leurs filons, pas plus que `monstre_carreau` ne doit être commun.
     */
    private function cleEpuisement(User $user, Interaction $interaction): string
    {
        $instanceId = $interaction->getPorteeRecharge() === PorteeRecharge::INSTANCE
            ? ($this->donjonInstanceService->instanceCourante($user)?->getId() ?? 0)
            : 0;

        return self::cleEpuisementPour($interaction, $instanceId);
    }

    /** Variante sans requête, pour `decrireCases()` qui a déjà résolu l'instance. */
    private static function cleEpuisementPour(Interaction $interaction, int $instanceId): string
    {
        return $interaction->getPorteeRecharge() === PorteeRecharge::INSTANCE
            ? 'instance:' . $instanceId . ':epuisement'
            // Jamais la clé « monde » nue : elle sert déjà aux interactions de portée
            // MONDE (coffres), et les deux verrous n'ont rien à voir.
            : 'monde:epuisement';
    }

    private function rechargeCourante(User $user, CarteCarreau $case, Interaction $interaction): ?InteractionRecharge
    {
        return $this->rechargeRepository->findOneBy([
            'carteCarreau' => $case,
            'cle' => $this->cleDePortee($user, $interaction),
        ]);
    }

    private function epuisementCourant(User $user, CarteCarreau $case, Interaction $interaction): ?InteractionRecharge
    {
        if (!$interaction->isRecolteChoix()) {
            return null;
        }

        return $this->rechargeRepository->findOneBy([
            'carteCarreau' => $case,
            'cle' => $this->cleEpuisement($user, $interaction),
        ]);
    }

    /** Cooldown personnel + épuisement partagé, en une passe. */
    private function poserRecharges(
        User $user,
        CarteCarreau $case,
        Interaction $interaction,
        ?ModeRecolte $mode
    ): void {
        $this->poserRecharge($user, $case, $interaction, $mode);
        $this->poserEpuisement($user, $case, $interaction, $mode);
    }

    private function poserRecharge(
        User $user,
        CarteCarreau $case,
        Interaction $interaction,
        ?ModeRecolte $mode = null
    ): void {
        // Sans cooldown ni usage unique, la case est réutilisable à volonté : rien à poser.
        if ($interaction->getCooldownSecondes() <= 0 && !$interaction->isUsageUnique()) {
            return;
        }

        $maintenant = new \DateTimeImmutable();
        $recharge = $this->rechargeCourante($user, $case, $interaction)
            ?? (new InteractionRecharge())
                ->setCarteCarreau($case)
                ->setCle($this->cleDePortee($user, $interaction));

        // Le mode module le délai que le joueur se coûte À LUI-MÊME : prélever avec
        // mesure laisse revenir plus tôt.
        $secondes = (int)round(
            $interaction->getCooldownSecondes()
            * ($mode !== null ? RecolteConfig::multiplicateurCooldown($mode) : 1.0)
        );

        $recharge->setUtiliseeAt($maintenant);
        $recharge->setDisponibleAt(
            $interaction->isUsageUnique()
                ? null
                : $maintenant->modify("+{$secondes} seconds")
        );

        $this->entityManager->persist($recharge);
    }

    /**
     * Épuisement du gisement : le délai que le joueur coûte AUX AUTRES. C'est le seul
     * effet du lot qui sorte du cadre d'un joueur seul.
     */
    private function poserEpuisement(
        User $user,
        CarteCarreau $case,
        Interaction $interaction,
        ?ModeRecolte $mode
    ): void {
        if ($mode === null || $interaction->getCooldownSecondes() <= 0) {
            return;
        }

        $facteur = RecolteConfig::multiplicateurEpuisement($mode);
        if ($facteur <= 0.0) {
            return; // récolte mesurée : rien n'est retiré aux autres
        }

        $maintenant = new \DateTimeImmutable();
        $secondes = (int)round($interaction->getCooldownSecondes() * $facteur);

        $epuisement = $this->epuisementCourant($user, $case, $interaction)
            ?? (new InteractionRecharge())
                ->setCarteCarreau($case)
                ->setCle($this->cleEpuisement($user, $interaction));

        $epuisement->setUtiliseeAt($maintenant);
        $epuisement->setDisponibleAt($maintenant->modify("+{$secondes} seconds"));

        $this->entityManager->persist($epuisement);
    }

    private function verifierProximite(User $user, CarteCarreau $case): void
    {
        $memeCarte = $case->getCarte()?->getId() === $user->getMap()?->getId();
        $adjacente = $memeCarte
            && abs($case->getAbscisse() - $user->getCaseAbscisse()) <= 1
            && abs($case->getOrdonnee() - $user->getCaseOrdonnee()) <= 1;

        if (!$adjacente) {
            throw new InteractionException("Vous êtes trop loin.");
        }
    }
}
