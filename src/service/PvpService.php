<?php

namespace App\service;

use App\Config\PvpConfig;
use App\DTO\CauseMort;
use App\Entity\Sortilege;
use App\Entity\User;
use App\Exception\PvpException;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNIQUE point d'entrée du duel entre joueurs : ciblage, garde-fous, coût, honneur,
 * expérience et attribution de la mort.
 *
 * ## `SpellService` n'est pas assaini, il est CANTONNÉ
 *
 * `SpellService` reste le CALCULATEUR de dégâts, partagé par le PvE, les boss et les donjons.
 * Le PvP, lui, est un JEU DE RÈGLES. Les mélanger est exactement ce qui avait produit un
 * contrôleur de soixante-dix lignes construisant du HTML — et ce qui avait laissé passer les
 * trous ci-dessous. `PvpService::attaquer()` appelle `SpellService::doDamage()` pour le
 * calcul, et porte tout le reste.
 *
 * ## Ce que le PvP ne vérifiait PAS avant ce lot
 *
 * - **Les points d'action n'étaient jamais décomptés.** `doDamageOnBoss` le faisait
 *   explicitement, `doDamage` (le chemin PvP) ne le faisait pas : attaquer un joueur était
 *   gratuit et illimité.
 * - Ni la carte, ni la portée, ni l'état de la cible, ni le `summoningSickness`. On pouvait
 *   frapper à travers le monde, et achever en boucle quelqu'un qui venait de ressusciter.
 *
 * Les gardes sont copiés sur `DonjonCombatService::verifierAttaqueBoss`, mécanique déjà
 * validée en jeu pour les boss.
 *
 * ⚠️ **Le cooldown par sort reste HORS de ce service, et c'est délibéré** : c'est un trou
 * GÉNÉRAL (`attackPlayerVsMonster` et `attackPlayerVsBoss` l'ont aussi). Le boucher côté PvP
 * seul créerait une asymétrie plus déroutante que le trou. En attendant, le PA redevenu
 * obligatoire fait office de cooldown.
 */
class PvpService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SpellService $spellService,
        private readonly DeathService $deathService,
        private readonly HonneurService $honneurService,
        private readonly LevelingService $levelingService,
        private readonly JournalService $journalService,
        private readonly UserRepository $userRepository,
        private readonly SortilegeRepository $sortilegeRepository,
        private readonly NiveauJoueurRepository $niveauJoueurRepository,
        // Ajouté EN FIN de liste, jamais au milieu : les tests montent ce service avec des
        // mocks positionnels, et une insertion intermédiaire les casse tous avec un
        // `TypeError` portant sur un argument sans rapport (CLAUDE.md).
        private readonly KarmaService $karmaService,
    ) {}

    /**
     * Attaque un joueur.
     *
     * @throws PvpException refus destiné au joueur
     */
    public function attaquer(User $attaquant, int $cibleId, int $sortId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($attaquant, $cibleId, $sortId): array {
            [$cible, $sort] = $this->cibleEtSort($attaquant, $cibleId, $sortId);

            if ($sort->getType() !== 'attack') {
                throw new PvpException("Ce sortilège n'est pas une attaque.");
            }

            $this->verifierAttaque($attaquant, $cible, $sort);
            $this->consommerPointsAction($attaquant, $sort);

            $degats = $this->spellService->doDamage($cible, $sort, $attaquant);
            $tue = ($degats['kill'] ?? false) === true;

            $niveauAttaquant = (int) $this->niveauJoueurRepository->getPlayerLevel($attaquant->getId());
            $niveauCible = (int) $this->niveauJoueurRepository->getPlayerLevel($cible->getId());

            $feuAmi = self::sontDuMemeCamp($attaquant, $cible);

            $honneur = null;
            $prixTrahison = null;
            $experience = 0;
            $messages = [sprintf(
                'Vous infligez %d points de dommages à %s.',
                (int) $degats['damage'],
                $cible->getPseudo()
            )];

            if ($tue && !$feuAmi) {
                // L'anti-farm est MESURÉ AVANT la mort : une fois `MORT_JOUEUR` consignée,
                // le kill courant compterait comme un kill « récent » et s'auto-annulerait
                // dès la première victoire. Mais il est APPLIQUÉ après, parce que
                // `diePlayer` finit par un `refresh()` de la victime qui effacerait un
                // honneur posé avant lui. Mesurer tôt, appliquer tard.
                $farm = $this->honneurService->dejaTueRecemment($attaquant, $cible);

                // La mort est jouée ICI, avec sa CAUSE : `acteur` = le tueur,
                // `cible_user` = le mort. C'est ce que `diePlayer` ne pouvait pas savoir
                // avant ce lot, et c'est ce qui alimente `JOUEURS_TUES`.
                $this->deathService->diePlayer($cible, CauseMort::pvp($attaquant));

                $honneur = $this->honneurService->appliquerVictoire(
                    $attaquant,
                    $cible,
                    $niveauAttaquant,
                    $niveauCible,
                    $farm
                );

                $experience = $farm ? 0 : PvpConfig::experiencePour($niveauAttaquant, $niveauCible);

                $messages[] = $farm
                    ? sprintf(
                        "%s tombe, mais vous l'avez déjà vaincu récemment : ni honneur ni expérience.",
                        $cible->getPseudo()
                    )
                    : sprintf(
                        '%s succombe. Honneur %+d, expérience +%d.',
                        $cible->getPseudo(),
                        $honneur['vainqueur']['delta'],
                        $experience
                    );
            } elseif ($tue) {
                $this->deathService->diePlayer($cible, CauseMort::pvp($attaquant, feuAmi: true));
                $messages[] = sprintf('%s tombe sous vos coups. Ce n\'était pas un ennemi.', $cible->getPseudo());
            }

            if ($feuAmi) {
                $prixTrahison = $this->facturerLeFeuAmi($attaquant, $cible, $tue);
                $messages[] = $prixTrahison['message'];
            }

            // ⚠️ JAMAIS `null` quand il n'y a pas de gain : `UsernameBlock` s'affiche sous
            // condition de `joueurState.level`, et un `level: null` remplace la fiche du
            // joueur par un chargement perpétuel jusqu'au F5. « Aucune XP gagnée » n'est
            // pas « aucun niveau » — on renvoie l'état courant.
            $niveau = $experience > 0
                ? $this->levelingService->giveExperienceToAPlayer($experience, $attaquant->getId())
                : $this->levelingService->etatDe($attaquant->getId());

            $this->entityManager->flush();

            // ⚠️ La réponse respecte le CONTRAT PARTAGÉ des endpoints d'attaque
            // (`Spell.jsx` consomme les trois de la même façon). Avoir livré une forme
            // « propre » mais différente a cassé l'écran : le front fait
            // `attackStats.droppedItems[0]`, qui lève un TypeError si la clé manque — et
            // l'exception empêchait `updateJoueurState`, laissant le ciblage mort jusqu'au
            // rechargement. Les champs sans objet en duel sont donc présents et neutres.
            return [
                // — contrat partagé —
                'damage' => (int) $degats['damage'],
                'experience' => $experience,
                'newExperience' => $niveau['experience'] ?? null,
                'level' => $niveau['level'] ?? null,
                // L'attaquant ne subit rien en duel : pas de riposte, pas de butin.
                'lifeJoueur' => $attaquant->getCurrentLife(),
                'damageReturns' => 0,
                'droppedItems' => [],
                // `killMessage` n'est pas décoratif : c'est lui qui fait retirer la cible
                // côté front. Sans lui, on reste accroché à un joueur mort.
                'killMessage' => $tue ? implode(' ', $messages) : null,
                'message' => implode(' ', $messages),
                'pa' => $attaquant->getActionPoint(),
                'needRefresh' => $tue,

                // — enrichissements propres au duel —
                'kill' => $tue,
                'niveau' => $niveau,
                'honneur' => $honneur,
                'feuAmi' => $prixTrahison,
                'lifeCible' => $cible->getCurrentLife(),
            ];
        });
    }

    /**
     * Soigne un joueur.
     *
     * Se soigner soi-même est autorisé mais ne rapporte AUCUNE expérience : sinon le
     * classement mesurerait la capacité à se taper dessus pour se recoudre.
     *
     * @throws PvpException
     */
    public function soigner(User $soigneur, int $cibleId, int $sortId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($soigneur, $cibleId, $sortId): array {
            [$cible, $sort] = $this->cibleEtSort($soigneur, $cibleId, $sortId, autoriserSoiMeme: true);

            if ($sort->getType() !== 'soin') {
                throw new PvpException("Ce sortilège n'est pas un soin.");
            }

            $this->verifierPortee($soigneur, $cible, $sort);
            $this->consommerPointsAction($soigneur, $sort);

            $soin = $this->spellService->healPlayer($cible, $sort, $soigneur);

            $surAutrui = $cible->getId() !== $soigneur->getId();
            $experience = $surAutrui ? PvpConfig::XP_SOIN : 0;
            // Même raison que dans `attaquer()` : se soigner soi-même ne rapporte rien, mais
            // renvoyer `level: null` éteindrait la fiche du joueur.
            $niveau = $experience > 0
                ? $this->levelingService->giveExperienceToAPlayer($experience, $soigneur->getId())
                : $this->levelingService->etatDe($soigneur->getId());

            $this->entityManager->flush();

            $message = $surAutrui
                ? sprintf('Vous soignez %s de %d points de vie.', $cible->getPseudo(), (int) $soin['value'])
                : sprintf('Vous récupérez %d points de vie.', (int) $soin['value']);

            // Même contrat partagé que l'attaque : voir le commentaire ci-dessus.
            return [
                'damage' => 0,
                'experience' => $experience,
                'newExperience' => $niveau['experience'] ?? null,
                'level' => $niveau['level'] ?? null,
                'lifeJoueur' => $soigneur->getCurrentLife(),
                'damageReturns' => 0,
                'droppedItems' => [],
                'killMessage' => null,
                'message' => $message,
                'pa' => $soigneur->getActionPoint(),
                'needRefresh' => false,

                'soin' => (int) $soin['value'],
                'niveau' => $niveau,
                'lifeCible' => $cible->getCurrentLife(),
            ];
        });
    }

    /**
     * Tous les garde-fous d'une attaque, côté SERVEUR.
     *
     * @throws PvpException
     */
    public function verifierAttaque(User $attaquant, User $cible, Sortilege $sort): void
    {
        if ($cible->getId() === $attaquant->getId()) {
            throw new PvpException("Vous ne pouvez pas vous attaquer vous-même.");
        }
        if ($cible->getCurrentLife() <= 0) {
            throw new PvpException("{$cible->getPseudo()} est déjà à terre.");
        }

        // Les 30 secondes qui suivent une mort. Sans ce garde-fou, on achève en boucle
        // quelqu'un qui vient de réapparaître au cimetière, sans qu'il puisse rien y faire.
        $maintenant = new \DateTime('now');
        if ($cible->getSummoningSickness() !== null && $cible->getSummoningSickness() > $maintenant) {
            throw new PvpException("{$cible->getPseudo()} vient de réapparaître : laissez-lui un instant.");
        }
        if ($attaquant->getSummoningSickness() !== null && $attaquant->getSummoningSickness() > $maintenant) {
            throw new PvpException("Vous venez de réapparaître : attendez avant d'attaquer.");
        }

        // Le feu ami n'est PAS un refus : frapper un allié est permis, et se paie
        // (`facturerLeFeuAmi`). La garde reste pilotée par la config pour qu'un futur
        // arbitrage puisse le réinterdire sans rouvrir ce fichier.
        if (!PvpConfig::FEU_AMI_AUTORISE && self::sontDuMemeCamp($attaquant, $cible)) {
            throw new PvpException("{$cible->getPseudo()} est de votre camp.");
        }

        $this->verifierPortee($attaquant, $cible, $sort);
        $this->verifierPointsAction($attaquant, $sort);
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Deux joueurs du même camp — donc deux alliés.
     *
     * Un alignement NULL n'allie personne : le sans-camp n'a pas de camarades, il ne doit
     * donc ni être protégé ni payer de trahison. Sans ce test, tous les joueurs qui n'ont
     * pas choisi d'alignement seraient alliés entre eux par accident.
     */
    private static function sontDuMemeCamp(User $un, User $autre): bool
    {
        $mien = $un->getAlignement()?->getId();

        return $mien !== null && $mien === $autre->getAlignement()?->getId();
    }

    /**
     * Le prix d'un coup porté à un allié : honneur ET karma, sur l'ATTAQUANT seul.
     *
     * Les deux valeurs ne mesurent pas la même chose et c'est pour ça qu'elles tombent
     * ensemble — l'honneur est la conduite en duel, le karma le rapport au monde. Trahir
     * salit les deux.
     *
     * **La victime ne perd rien**, contrairement à une défaite en duel : se faire poignarder
     * par son camp n'est pas un échec au combat. C'est aussi ce qui empêche la trahison de
     * devenir une arme — sinon deux comptes complices se feraient tomber mutuellement
     * l'honneur d'un rival… qui n'aurait rien fait.
     *
     * Une mise à mort ne rapporte NI honneur NI expérience : `attaquer()` court-circuite
     * `appliquerVictoire`. Il n'y a pas de victoire à célébrer.
     */
    private function facturerLeFeuAmi(User $attaquant, User $cible, bool $tue): array
    {
        $honneur = $this->honneurService->ajuster(
            $attaquant,
            PvpConfig::FEU_AMI_HONNEUR_PAR_COUP + ($tue ? PvpConfig::FEU_AMI_HONNEUR_MISE_A_MORT : 0)
        );
        $karma = $this->karmaService->ajuster(
            $attaquant,
            PvpConfig::FEU_AMI_KARMA_PAR_COUP + ($tue ? PvpConfig::FEU_AMI_KARMA_MISE_A_MORT : 0)
        );

        return [
            'honneur' => $honneur,
            'karma' => $karma,
            'miseAMort' => $tue,
            'message' => sprintf(
                '%s est de votre camp : honneur %+d, karma %+d.',
                $cible->getPseudo(),
                $honneur['delta'],
                $karma['delta']
            ),
        ];
    }

    /** @throws PvpException */
    private function verifierPortee(User $lanceur, User $cible, Sortilege $sort): void
    {
        if ($cible->getMap()?->getId() !== $lanceur->getMap()?->getId()) {
            throw new PvpException("{$cible->getPseudo()} n'est pas sur cette carte.");
        }

        // Distance de Chebyshev, comme pour les boss : les diagonales comptent pour 1.
        $distance = max(
            abs($cible->getCaseAbscisse() - $lanceur->getCaseAbscisse()),
            abs($cible->getCaseOrdonnee() - $lanceur->getCaseOrdonnee())
        );
        if ($distance > (int) $sort->getPortee()) {
            throw new PvpException("{$cible->getPseudo()} est hors de portée de {$sort->getNom()}.");
        }
    }

    /** @throws PvpException */
    private function verifierPointsAction(User $lanceur, Sortilege $sort): void
    {
        if ($lanceur->getActionPoint() < (int) $sort->getPointAction()) {
            throw new PvpException("Vous n'avez plus assez de points d'action pour lancer {$sort->getNom()}.");
        }
    }

    /**
     * Décompte les PA. L'absence de cette ligne rendait le PvP GRATUIT.
     *
     * @throws PvpException
     */
    private function consommerPointsAction(User $lanceur, Sortilege $sort): void
    {
        $this->verifierPointsAction($lanceur, $sort);
        $lanceur->setActionPoint($lanceur->getActionPoint() - (int) $sort->getPointAction());
        $this->entityManager->persist($lanceur);
    }

    /**
     * @return array{0: User, 1: Sortilege}
     * @throws PvpException
     */
    private function cibleEtSort(User $lanceur, int $cibleId, int $sortId, bool $autoriserSoiMeme = false): array
    {
        $cible = $this->userRepository->find($cibleId);
        if ($cible === null) {
            throw new PvpException("Ce joueur n'existe pas.");
        }
        if (!$autoriserSoiMeme && $cible->getId() === $lanceur->getId()) {
            throw new PvpException("Vous ne pouvez pas vous cibler vous-même.");
        }

        $sort = $this->sortilegeRepository->find($sortId);
        if ($sort === null) {
            throw new PvpException("Ce sortilège n'existe pas.");
        }

        return [$cible, $sort];
    }
}
