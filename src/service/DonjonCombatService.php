<?php

namespace App\service;

use App\DTO\CauseMort;

use App\Entity\Boss;
use App\Entity\DonjonInstance;
use App\Entity\DonjonInstanceMembre;
use App\Entity\DonjonInstanceMonstre;
use App\Entity\DonjonInstanceZone;
use App\Entity\DonjonMecanique;
use App\Entity\Sortilege;
use App\Entity\User;
use App\Enum\MecaniqueDonjon;
use App\Exception\DonjonException;
use App\Repository\CarteCarreauRepository;
use App\Repository\DonjonInstanceLevierRepository;
use App\Repository\DonjonInstanceMonstreRepository;
use App\Repository\DonjonInstanceZoneRepository;
use App\Repository\DonjonMecaniqueRepository;
use App\Repository\MonstreRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine de combat des donjons : menace, phases, mécaniques et tick.
 * Aucun contrôleur ni autre service n'écrit dans `donjon_instance_zone` /
 * `donjon_instance_monstre` / `donjon_instance_levier`.
 *
 * ## Le tick est PARESSEUX
 * Le combat du jeu est asynchrone (pas de tour) : sans horloge, « annoncer une zone qui
 * frappe dans 10 s » n'a aucun sens. Plutôt qu'une tâche planifiée — le scheduler tourne
 * à la minute, beaucoup trop grossier — le tick est joué au fil des requêtes des joueurs
 * de l'instance (`jouerTick`), sur la base du temps RÉELLEMENT écoulé. Deux conséquences
 * voulues :
 *   - aucune dérive entre l'horloge serveur et ce que voit le joueur ;
 *   - un groupe qui ne fait rien ne fait rien avancer, ce qui est le comportement
 *     attendu d'une rencontre qu'on a mise en pause.
 *
 * ## Les phases ne sont pas une entité
 * Une mécanique est bornée par une fenêtre de vie du boss (vieMax → vieMin en %).
 * « Invoquer des renforts à 75 %, enrager à 25 % » = deux lignes de `donjon_mecanique`,
 * sans table de phases ni code dédié.
 */
class DonjonCombatService
{
    /** Menace gagnée par point de dégât infligé au boss. */
    private const MENACE_PAR_DEGAT = 1;

    /** Menace gagnée en soignant : garde les soigneurs dans la table sans les mettre devant. */
    private const MENACE_PAR_SOIN = 0.5;

    public function __construct(
        private readonly DonjonMecaniqueRepository $mecaniqueRepository,
        private readonly DonjonInstanceZoneRepository $zoneRepository,
        private readonly DonjonInstanceMonstreRepository $monstreInstanceRepository,
        private readonly DonjonInstanceLevierRepository $levierRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly MonstreRepository $monstreRepository,
        private readonly DeathService $deathService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Garde-fous d'attaque                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Contrôles SERVEUR avant un coup porté au boss. Ils n'existaient pas : le client
     * pouvait frapper à travers la carte, sans PA, et faire passer ses PA en négatif.
     * Sans eux, « portée » et « déplacement » ne veulent rien dire, donc aucune mécanique
     * de positionnement n'est crédible.
     */
    public function verifierAttaqueBoss(User $user, Boss $boss, Sortilege $spell): void
    {
        if ($user->getActionPoint() < $spell->getPointAction()) {
            throw new DonjonException("Vous n'avez plus assez de points d'action pour lancer {$spell->getNom()}.");
        }

        $case = $this->caseDuBoss($boss);
        if ($case === null) {
            return; // Boss sans case posée : rien à vérifier (contenu incomplet).
        }

        if ($case->getCarte()?->getId() !== $user->getMap()?->getId()) {
            throw new DonjonException("{$boss->getName()} n'est pas sur cette carte.");
        }

        $distance = max(
            abs($case->getAbscisse() - $user->getCaseAbscisse()),
            abs($case->getOrdonnee() - $user->getCaseOrdonnee())
        );
        if ($distance > $spell->getPortee()) {
            throw new DonjonException("{$boss->getName()} est hors de portée de {$spell->getNom()}.");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Menace                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Le boss frappe la plus GROSSE menace présente, pas le dernier attaquant.
     * C'est le seul choix de conception qui fait exister le rôle de tank — et donc la
     * coordination — sans rien ajouter à l'interface.
     *
     * **Le boss ne voit que SA salle.** Passer `$boss` restreint les candidats aux membres
     * présents sur la carte où il se tient : sans ce filtre il continuait de frapper (et de
     * télégraphier ses zones sur) un joueur mort reparti dans la salle 1, ce qui est
     * injouable et incompréhensible. Sans `$boss`, aucun filtre — la menace pure, ce que
     * testent les tests unitaires.
     */
    public function cibleDuBoss(DonjonInstance $instance, ?Boss $boss = null): ?DonjonInstanceMembre
    {
        $carteDuBoss = $boss === null ? null : $this->caseDuBoss($boss)?->getCarte()?->getId();

        $meilleur = null;
        foreach ($instance->membresPresents() as $membre) {
            $joueur = $membre->getUser();
            if ($joueur?->getCurrentLife() <= 0) {
                continue;
            }
            if ($carteDuBoss !== null && $joueur?->getMap()?->getId() !== $carteDuBoss) {
                continue;
            }
            if ($meilleur === null || $membre->getMenace() > $meilleur->getMenace()) {
                $meilleur = $membre;
            }
        }

        return $meilleur;
    }

    public function ajouterMenaceDegats(DonjonInstance $instance, User $user, int $degats): void
    {
        $membre = $instance->membrePour($user);
        if ($membre === null) {
            return;
        }

        $membre->ajouterMenace((int)round($degats * self::MENACE_PAR_DEGAT));
        $this->entityManager->persist($membre);
    }

    public function ajouterMenaceSoin(DonjonInstance $instance, User $user, int $soin): void
    {
        $membre = $instance->membrePour($user);
        if ($membre === null) {
            return;
        }

        $membre->ajouterMenace((int)round($soin * self::MENACE_PAR_SOIN));
        $this->entityManager->persist($membre);
    }

    /* ------------------------------------------------------------------ */
    /* Tick                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Fait avancer la rencontre : résout les zones échues, déclenche les mécaniques de la
     * phase courante. Idempotent et sans effet tant que le boss n'est pas engagé.
     *
     * @return array{messages: string[], degatsSubis: array<int,int>, zones: array}
     */
    public function jouerTick(DonjonInstance $instance, Boss $boss, ?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $messages = [];
        $degatsSubis = [];

        if (!$instance->combatEngage() || $instance->getStatut()->estTerminal()) {
            return ['messages' => [], 'degatsSubis' => [], 'zones' => []];
        }

        // 1. Les zones annoncées au tick précédent frappent maintenant.
        $tues = [];
        foreach ($this->zoneRepository->findBy(['instance' => $instance, 'resolue' => false]) as $zone) {
            if (!$zone->estEchue($maintenant)) {
                continue;
            }
            foreach ($this->resoudreZone($instance, $zone) as $userId => $touche) {
                $degatsSubis[$userId] = ($degatsSubis[$userId] ?? 0) + $touche['degats'];
                if ($touche['mort']) {
                    $tues[$userId] = $touche['joueur'];
                }
            }
            $messages[] = $zone->getAnnonce() ?? "Le sol se fissure sous les imprudents.";
        }

        // 2. Mécaniques de la phase courante.
        $pourcentageVie = $this->pourcentageVie($instance, $boss);
        foreach ($this->mecaniqueRepository->findBy(['donjon' => $instance->getDonjon(), 'actif' => true], ['ordre' => 'ASC']) as $mecanique) {
            if (!$mecanique->couvre($pourcentageVie) || !$this->cooldownEcoule($instance, $mecanique, $maintenant)) {
                continue;
            }

            $message = $this->declencher($instance, $boss, $mecanique, $maintenant);
            if ($message !== null) {
                $messages[] = $message;
                $instance->marquerDeclenchement($mecanique->getId(), $maintenant);
            }
        }

        $instance->setDernierTickAt($maintenant);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        // 3. Une zone qui met à 0 TUE. Ce n'était traité nulle part : le joueur restait
        //    en vie négative, sur la carte du donjon, libre de se déplacer et toujours
        //    ciblé par le boss — le « zombie » constaté en jeu. La mort est jouée APRÈS le
        //    flush ci-dessus, car `diePlayer` écrit en DQL puis resynchronise l'entité :
        //    l'inverse ferait réécrire l'état d'avant la mort par ce flush.
        foreach ($tues as $joueur) {
            $this->deathService->diePlayer($joueur, CauseMort::zoneDonjon((int)$instance->getId()));
            $messages[] = "{$joueur->getPseudo()} succombe et est renvoyé au cimetière.";
        }

        return [
            'messages' => $messages,
            'degatsSubis' => $degatsSubis,
            'zones' => $this->zonesEnAttente($instance),
        ];
    }

    /** Multiplicateur de dégâts du boss dû à l'enrage (1.0 tant qu'il n'est pas déclenché). */
    public function multiplicateurEnrage(DonjonInstance $instance, ?\DateTimeImmutable $maintenant = null): float
    {
        if (!$instance->combatEngage()) {
            return 1.0;
        }

        $maintenant ??= new \DateTimeImmutable();
        $ecoule = $maintenant->getTimestamp() - $instance->getCombatDebutAt()->getTimestamp();
        $multiplicateur = 1.0;

        foreach ($this->mecaniquesDeType($instance, MecaniqueDonjon::ENRAGE) as $mecanique) {
            if ($ecoule >= (int)$mecanique->param('apresSecondes')) {
                $multiplicateur = max($multiplicateur, (float)$mecanique->param('multiplicateur'));
            }
        }

        return $multiplicateur;
    }

    /** Marque le début du combat au premier coup porté : origine du chrono d'enrage. */
    public function engagerCombat(DonjonInstance $instance, ?\DateTimeImmutable $maintenant = null): void
    {
        if ($instance->combatEngage()) {
            return;
        }

        $instance->setCombatDebutAt($maintenant ?? new \DateTimeImmutable());
        $this->entityManager->persist($instance);
    }

    /** Les zones encore en attente, pour que le front les surligne. */
    public function zonesEnAttente(DonjonInstance $instance): array
    {
        $zones = [];
        foreach ($this->zoneRepository->findBy(['instance' => $instance, 'resolue' => false]) as $zone) {
            $zones[] = [
                'id' => $zone->getId(),
                'carteId' => $zone->getCarteId(),
                'cases' => $zone->getCases(),
                'degats' => $zone->getDegats(),
                'resoudreAt' => $zone->getResoudreAt()?->format(\DateTimeInterface::ATOM),
                'annonce' => $zone->getAnnonce(),
            ];
        }

        return $zones;
    }

    /* ------------------------------------------------------------------ */
    /* Énigme à leviers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Actionne un levier. L'énigme est résolue quand N leviers DISTINCTS ont été
     * actionnés par des joueurs DIFFÉRENTS dans la fenêtre de temps : c'est ce qui
     * force la répartition dans la salle plutôt que l'empilement des dégâts.
     *
     * @return array{messages: string[], resolue: bool, degatsBoss: int}
     */
    /**
     * Enregistre le geste, sans rien évaluer.
     *
     * Séparé de l'évaluation parce qu'un même levier commande DEUX choses : une porte de
     * salle et l'énigme de combat du boss. L'évaluation du boss consomme les leviers ;
     * si elle passait en premier, la porte ne verrait plus rien.
     */
    public function enregistrerLevier(DonjonInstance $instance, User $user, int $carteCarreauId): void
    {
        $existant = $this->levierRepository->findOneBy([
            'instance' => $instance,
            'carteCarreauId' => $carteCarreauId,
        ]);

        if ($existant !== null) {
            $existant->setActionnePar($user)->setActionneAt(new \DateTimeImmutable());
            $this->entityManager->persist($existant);
        } else {
            $levier = (new \App\Entity\DonjonInstanceLevier())
                ->setInstance($instance)
                ->setCarteCarreauId($carteCarreauId)
                ->setActionnePar($user);
            $this->entityManager->persist($levier);
        }

        $this->entityManager->flush();
    }

    public function actionnerLevier(DonjonInstance $instance, User $user, int $carteCarreauId): array
    {
        $mecaniques = $this->mecaniquesDeType($instance, MecaniqueDonjon::ENIGME_LEVIERS);
        if ($mecaniques === []) {
            throw new DonjonException("Ce levier ne commande rien ici.");
        }
        $mecanique = $mecaniques[0];

        $maintenant = new \DateTimeImmutable();
        $fenetre = (int)$mecanique->param('fenetreSecondes');
        $requis = (int)$mecanique->param('leviers');

        // Leviers encore « chauds », comptés par joueur distinct.
        $limite = $maintenant->modify("-{$fenetre} seconds");
        $joueurs = [];
        $chauds = 0;
        foreach ($this->levierRepository->findBy(['instance' => $instance]) as $levier) {
            if ($levier->getActionneAt() < $limite) {
                continue;
            }
            $chauds++;
            $joueurs[$levier->getActionnePar()?->getId()] = true;
        }

        if ($chauds < $requis || count($joueurs) < $requis) {
            $manquants = max(1, $requis - min($chauds, count($joueurs)));

            return [
                'messages' => ["Le levier résiste. Il en faut {$manquants} de plus, actionné"
                    . ($manquants > 1 ? 's' : '') . " en même temps par d'autres membres du groupe."],
                'resolue' => false,
                'degatsBoss' => 0,
            ];
        }

        // Énigme résolue : le boss encaisse et les leviers se réarment.
        $degats = (int)$mecanique->param('degatsBoss');
        foreach ($this->levierRepository->findBy(['instance' => $instance]) as $levier) {
            $this->entityManager->remove($levier);
        }
        $this->entityManager->flush();

        return [
            'messages' => [$mecanique->getAnnonce() ?? "Les mécanismes s'enclenchent : le gardien vacille."],
            'resolue' => true,
            'degatsBoss' => $degats,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Renforts                                                            */
    /* ------------------------------------------------------------------ */

    /** @return DonjonInstanceMonstre[] renforts vivants de l'instance sur une carte */
    public function renfortsVivants(DonjonInstance $instance, ?int $carteId = null): array
    {
        $criteres = ['instance' => $instance, 'vivant' => true];
        if ($carteId !== null) {
            $criteres['carteId'] = $carteId;
        }

        return $this->monstreInstanceRepository->findBy($criteres);
    }

    /**
     * Frappe un renfort. Mêmes garde-fous que le boss (PA, carte, portée) : un renfort
     * qu'on peut tuer depuis l'autre bout de la salle ne force personne à se répartir.
     *
     * @return array{degats: int, vieRestante: int, mort: bool, riposte: int, messages: string[]}
     */
    public function frapperRenfort(
        DonjonInstance $instance,
        DonjonInstanceMonstre $renfort,
        User $user,
        Sortilege $spell,
        int $degats,
        int $armureJoueur
    ): array {
        // Messages destinés au JOUEUR : ils nomment la créature, jamais « le renfort » —
        // c'est du vocabulaire interne, et pour le joueur c'est un monstre comme un autre.
        $nom = $renfort->getMonstre()->getName();

        if (!$renfort->isVivant()) {
            throw new DonjonException("{$nom} est déjà tombé.");
        }
        if ($renfort->getInstance()?->getId() !== $instance->getId()) {
            throw new DonjonException("Cette créature n'appartient pas à votre expédition.");
        }
        if ($user->getActionPoint() < $spell->getPointAction()) {
            throw new DonjonException("Vous n'avez plus assez de points d'action pour lancer {$spell->getNom()}.");
        }
        if ($renfort->getCarteId() !== $user->getMap()?->getId()) {
            throw new DonjonException("{$nom} n'est pas sur cette carte.");
        }

        $distance = max(
            abs($renfort->getAbscisse() - $user->getCaseAbscisse()),
            abs($renfort->getOrdonnee() - $user->getCaseOrdonnee())
        );
        if ($distance > $spell->getPortee()) {
            throw new DonjonException("{$nom} est hors de portée de {$spell->getNom()}.");
        }

        $renfort->setCurrentLife($renfort->getCurrentLife() - $degats);
        $user->setActionPoint($user->getActionPoint() - $spell->getPointAction());

        $messages = [];
        $riposte = 0;
        if ($renfort->isVivant()) {
            $puissance = $renfort->getMonstre()->getPuissance();
            $riposte = (int)max(0, floor(mt_rand($puissance, (int)($puissance * 2.2))) - ($armureJoueur * 0.2));
            $user->setCurrentLife($user->getCurrentLife() - $riposte);
        } else {
            $messages[] = "{$renfort->getMonstre()->getName()} s'effondre.";
        }

        $this->entityManager->persist($renfort);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return [
            'degats' => $degats,
            'vieRestante' => $renfort->getCurrentLife(),
            'mort' => !$renfort->isVivant(),
            'riposte' => $riposte,
            'messages' => $messages,
        ];
    }

    /**
     * État de combat pour le front : vie du boss, phase, menace, zones en attente,
     * renforts. Joue le tick au passage — c'est l'appel que fait le front pendant un
     * combat, donc le meilleur endroit pour faire avancer la rencontre.
     */
    public function etatCombat(DonjonInstance $instance, ?Boss $boss): array
    {
        $tick = $boss !== null
            ? $this->jouerTick($instance, $boss)
            : ['messages' => [], 'degatsSubis' => [], 'zones' => []];

        $menaces = [];
        foreach ($instance->membresPresents() as $membre) {
            $menaces[] = [
                'userId' => $membre->getUser()->getId(),
                'pseudo' => $membre->getUser()->getPseudo(),
                'menace' => $membre->getMenace(),
                'vie' => $membre->getUser()->getCurrentLife(),
                'vieMax' => $membre->getUser()->getMaxLife(),
            ];
        }
        usort($menaces, fn (array $a, array $b) => $b['menace'] <=> $a['menace']);

        $renforts = array_map(fn (DonjonInstanceMonstre $renfort) => [
            'id' => $renfort->getId(),
            'nom' => $renfort->getMonstre()->getName(),
            // Les renforts apparaissent en cours de partie : contrairement aux monstres
            // du monde ouvert (peints dans l'image de fond), le front doit les dessiner.
            'skin' => $renfort->getMonstre()->getSkin(),
            'carteId' => $renfort->getCarteId(),
            'abscisse' => $renfort->getAbscisse(),
            'ordonnee' => $renfort->getOrdonnee(),
            'vie' => $renfort->getCurrentLife(),
            'vieMax' => $renfort->getMonstre()->getMaxLife(),
        ], $this->renfortsVivants($instance));

        return [
            'instanceId' => $instance->getId(),
            'statut' => $instance->getStatut()->value,
            'boss' => $boss === null ? null : [
                'id' => $boss->getId(),
                'nom' => $boss->getName(),
                'vie' => $instance->getBossCurrentLife() ?? $boss->getMaxLife(),
                'vieMax' => $boss->getMaxLife(),
                'phase' => $this->pourcentageVie($instance, $boss),
                'enrage' => $this->multiplicateurEnrage($instance) > 1.0,
            ],
            'combatEngage' => $instance->combatEngage(),
            'menaces' => $menaces,
            'zones' => $tick['zones'],
            'renforts' => $renforts,
            'messages' => $tick['messages'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function declencher(
        DonjonInstance $instance,
        Boss $boss,
        DonjonMecanique $mecanique,
        \DateTimeImmutable $maintenant
    ): ?string {
        return match ($mecanique->getType()) {
            MecaniqueDonjon::ZONE_TELEGRAPHIEE => $this->annoncerZone($instance, $boss, $mecanique, $maintenant),
            MecaniqueDonjon::ADDS => $this->invoquerRenforts($instance, $boss, $mecanique),
            // L'enrage n'est pas un évènement mais un état continu (multiplicateurEnrage) :
            // le tick ne fait que l'annoncer une fois.
            MecaniqueDonjon::ENRAGE => $this->annoncerEnrage($instance, $mecanique, $maintenant),
            // L'énigme est déclenchée par les joueurs, pas par le boss.
            MecaniqueDonjon::ENIGME_LEVIERS => null,
        };
    }

    private function annoncerZone(
        DonjonInstance $instance,
        Boss $boss,
        DonjonMecanique $mecanique,
        \DateTimeImmutable $maintenant
    ): ?string {
        // Cible restreinte à la salle du boss : une zone annoncée sur une autre salle est
        // à la fois injouable (personne ne peut s'en écarter en la voyant) et absurde.
        $cible = $this->cibleDuBoss($instance, $boss);
        if ($cible === null) {
            return null;
        }

        $centre = $cible->getUser();
        $carteId = $centre->getMap()?->getId();
        if ($carteId === null || !$instance->contientCarte($carteId)) {
            return null;
        }

        $rayon = max(0, (int)$mecanique->param('rayon'));
        $cases = [];
        for ($dx = -$rayon; $dx <= $rayon; $dx++) {
            for ($dy = -$rayon; $dy <= $rayon; $dy++) {
                $cases[] = [
                    'abscisse' => $centre->getCaseAbscisse() + $dx,
                    'ordonnee' => $centre->getCaseOrdonnee() + $dy,
                ];
            }
        }

        $delai = max(1, (int)$mecanique->param('delaiSecondes'));
        $annonce = $mecanique->getAnnonce()
            ?? "{$boss->getName()} vise le sol : écartez-vous !";

        $zone = (new DonjonInstanceZone())
            ->setInstance($instance)
            ->setCarteId($carteId)
            ->setCases($cases)
            ->setDegats((int)$mecanique->param('degats'))
            ->setResoudreAt($maintenant->modify("+{$delai} seconds"))
            ->setAnnonce($annonce);

        $this->entityManager->persist($zone);

        return $annonce;
    }

    /**
     * Applique les dégâts d'une zone échue aux joueurs QUI S'Y TROUVENT ENCORE.
     * Les autres s'en sortent : c'est tout l'intérêt du délai d'annonce.
     *
     * La mise à mort n'est pas jouée ici : `jouerTick` la joue après son flush (voir là-bas).
     *
     * @return array<int, array{degats: int, mort: bool, joueur: User}> indexé par userId
     */
    private function resoudreZone(DonjonInstance $instance, DonjonInstanceZone $zone): array
    {
        $touches = [];

        foreach ($instance->membresPresents() as $membre) {
            $joueur = $membre->getUser();
            if ($joueur->getMap()?->getId() !== $zone->getCarteId()) {
                continue;
            }
            if (!$zone->couvre($joueur->getCaseAbscisse(), $joueur->getCaseOrdonnee())) {
                continue;
            }

            $joueur->setCurrentLife($joueur->getCurrentLife() - $zone->getDegats());
            $this->entityManager->persist($joueur);
            $touches[$joueur->getId()] = [
                'degats' => $zone->getDegats(),
                'mort' => $joueur->getCurrentLife() <= 0,
                'joueur' => $joueur,
            ];
        }

        $zone->setResolue(true);
        $this->entityManager->persist($zone);

        return $touches;
    }

    private function invoquerRenforts(DonjonInstance $instance, Boss $boss, DonjonMecanique $mecanique): ?string
    {
        $monstre = $this->monstreRepository->find((int)$mecanique->param('monstreId'));
        if ($monstre === null) {
            return null;
        }

        $caseBoss = $this->caseDuBoss($boss);
        if ($caseBoss === null) {
            return null;
        }

        $carteId = $caseBoss->getCarte()->getId();
        $quantite = max(1, (int)$mecanique->param('quantite'));
        $positions = $this->casesAutour($carteId, $caseBoss->getAbscisse(), $caseBoss->getOrdonnee(), $quantite);

        foreach ($positions as $position) {
            $renfort = (new DonjonInstanceMonstre())
                ->setInstance($instance)
                ->setMonstre($monstre)
                ->setCarteId($carteId)
                ->setAbscisse($position['abscisse'])
                ->setOrdonnee($position['ordonnee'])
                ->setCurrentLife($monstre->getMaxLife());

            $this->entityManager->persist($renfort);
        }

        return $mecanique->getAnnonce()
            ?? "{$boss->getName()} appelle des renforts : {$quantite} {$monstre->getName()} surgissent !";
    }

    private function annoncerEnrage(
        DonjonInstance $instance,
        DonjonMecanique $mecanique,
        \DateTimeImmutable $maintenant
    ): ?string {
        $ecoule = $maintenant->getTimestamp() - $instance->getCombatDebutAt()->getTimestamp();
        if ($ecoule < (int)$mecanique->param('apresSecondes')) {
            return null;
        }

        return $mecanique->getAnnonce() ?? "Le gardien entre en furie : ses coups redoublent !";
    }

    /** @return DonjonMecanique[] */
    private function mecaniquesDeType(DonjonInstance $instance, MecaniqueDonjon $type): array
    {
        return $this->mecaniqueRepository->findBy(
            ['donjon' => $instance->getDonjon(), 'type' => $type, 'actif' => true],
            ['ordre' => 'ASC']
        );
    }

    /**
     * Cooldown 0 = la mécanique ne se déclenche qu'UNE fois par instance (typique d'une
     * entrée en phase) ; sinon elle se rejoue à l'expiration du cooldown.
     */
    private function cooldownEcoule(
        DonjonInstance $instance,
        DonjonMecanique $mecanique,
        \DateTimeImmutable $maintenant
    ): bool {
        $dernier = $instance->dernierDeclenchement($mecanique->getId());
        if ($dernier === null) {
            return true;
        }

        if ($mecanique->getCooldownSecondes() <= 0) {
            return false;
        }

        return ($maintenant->getTimestamp() - $dernier->getTimestamp()) >= $mecanique->getCooldownSecondes();
    }

    private function pourcentageVie(DonjonInstance $instance, Boss $boss): int
    {
        $max = max(1, $boss->getMaxLife());
        $courante = $instance->getBossCurrentLife() ?? $max;

        return (int)floor($courante / $max * 100);
    }

    private function caseDuBoss(Boss $boss)
    {
        return $this->carteCarreauRepository->findOneBy(['boss' => $boss]);
    }

    /** @return array<int, array{abscisse: int, ordonnee: int}> */
    private function casesAutour(int $carteId, int $abscisse, int $ordonnee, int $combien): array
    {
        $cases = $this->carteCarreauRepository->getAllCasesOfMap($carteId);
        $index = [];
        foreach ($cases as $case) {
            $index[$case['abscisse'] . ':' . $case['ordonnee']] = $case;
        }

        $retenues = [];
        for ($rayon = 1; $rayon <= 3 && count($retenues) < $combien; $rayon++) {
            for ($dx = -$rayon; $dx <= $rayon && count($retenues) < $combien; $dx++) {
                for ($dy = -$rayon; $dy <= $rayon && count($retenues) < $combien; $dy++) {
                    if (max(abs($dx), abs($dy)) !== $rayon) {
                        continue;
                    }
                    $case = $index[($abscisse + $dx) . ':' . ($ordonnee + $dy)] ?? null;
                    if ($case === null || !$case['isUsable'] || $case['isWrap'] || $case['pnjName'] !== null) {
                        continue;
                    }
                    $retenues[] = ['abscisse' => $case['abscisse'], 'ordonnee' => $case['ordonnee']];
                }
            }
        }

        return $retenues;
    }
}
