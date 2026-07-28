<?php

namespace App\Tests\Service;

use App\Entity\Action;
use App\Entity\Carte;
use App\Entity\CarteCarreau;
use App\Entity\Monstre;
use App\Entity\Objet;
use App\Entity\Pnj;
use App\Entity\Quete;
use App\Entity\Recette;
use App\Entity\Recompense;
use App\Entity\Sequence;
use App\Entity\SequenceAction;
use App\Entity\User;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Enum\TypeCompteur;
use App\Exception\QuestException;
use App\Exception\UnsupportedQuestActionException;
use App\Repository\ActionRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\CompteurJoueurRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserQueteRepository;
use App\service\CompteurJoueurService;
use App\service\KarmaService;
use App\service\LevelingService;
use App\service\RecompenseService;
use App\service\SacService;
use App\service\QuestEffectRegistry;
use App\service\QuestProgressionService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la machine à états des quêtes : démarrage (prérequis),
 * garde-fous d'exécution, conditions, avancement par position, récompenses
 * (régression du bug objet → inventaire consommables) et fin de quête.
 */
class QuestProgressionServiceTest extends TestCase
{
    private SequenceRepository|MockObject $sequenceRepository;
    private UserQueteRepository|MockObject $userQueteRepository;
    private ActionRepository|MockObject $actionRepository;
    private NiveauJoueurRepository|MockObject $niveauJoueurRepository;
    private UserBossRepository|MockObject $userBossRepository;
    private CarteCarreauRepository|MockObject $carteCarreauRepository;
    private SacService|MockObject $sacService;
    private LevelingService|MockObject $levelingService;
    private QuestEffectRegistry|MockObject $effectRegistry;
    private CompteurJoueurRepository|MockObject $compteurRepository;
    /* EntityManager concret : wrapInTransaction n'existe sur l'interface
       que par annotation @method (ORM 2.x), donc non mockable dessus. */
    private EntityManager|MockObject $entityManager;
    private QuestProgressionService $service;

    /** Compteurs simulés : "type:cible" => valeur cumulée du joueur. */
    private array $compteurs = [];

    protected function setUp(): void
    {
        $this->sequenceRepository = $this->createMock(SequenceRepository::class);
        $this->userQueteRepository = $this->createMock(UserQueteRepository::class);
        $this->actionRepository = $this->createMock(ActionRepository::class);
        $this->niveauJoueurRepository = $this->createMock(NiveauJoueurRepository::class);
        $this->userBossRepository = $this->createMock(UserBossRepository::class);
        $this->carteCarreauRepository = $this->createMock(CarteCarreauRepository::class);
        $this->levelingService = $this->createMock(LevelingService::class);
        $this->effectRegistry = $this->createMock(QuestEffectRegistry::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());

        /* SacService est mocké (son comportement est couvert par SacServiceTest) mais on
           reproduit les mouvements d'or pour vérifier les soldes en sortie de quête. */
        $this->sacService = $this->createMock(SacService::class);
        $this->sacService->method('orDisponible')->willReturnCallback(fn (User $user) => (int) $user->getMoney());
        $this->sacService->method('debiterOr')->willReturnCallback(function (User $user, int $montant): void {
            $user->setMoney($user->getMoney() - $montant);
        });
        $this->sacService->method('crediterOr')->willReturnCallback(function (User $user, int $montant): void {
            $user->setMoney($user->getMoney() + $montant);
        });

        /* Les compteurs sont un simple tableau en mémoire : le SQL d'upsert est du
           ressort du repository (couvert en fonctionnel), ce qui se teste ici est la
           soustraction de l'instantané de départ. */
        $this->compteurs = [];
        $this->compteurRepository = $this->createMock(CompteurJoueurRepository::class);
        $this->compteurRepository->method('valeur')->willReturnCallback(
            fn (User $user, $type, int $cibleId): int => $this->compteurs[$type->cle($cibleId)] ?? 0
        );

        $this->service = new QuestProgressionService(
            $this->sequenceRepository,
            $this->userQueteRepository,
            $this->actionRepository,
            $this->niveauJoueurRepository,
            $this->userBossRepository,
            $this->carteCarreauRepository,
            $this->sacService,
            // RecompenseService réel (branché sur les mocks) : les assertions de ce
            // fichier portent sur les effets finaux en inventaire/or, pas sur la délégation.
            new RecompenseService($this->sacService, $this->levelingService),
            $this->effectRegistry,
            new CompteurJoueurService($this->compteurRepository),
            new KarmaService($this->entityManager),
            $this->entityManager
        );
    }

    /* ---------------------------------------------------------------- */
    /* Démarrage                                                         */
    /* ---------------------------------------------------------------- */

    public function testStartQuestRefuseUnPnjSansQuete(): void
    {
        $pnj = new Pnj();

        $this->expectException(QuestException::class);
        $this->service->startQuest($this->makeUser(1), $pnj);
    }

    public function testStartQuestBloqueSiNiveauInsuffisant(): void
    {
        $quete = $this->makeQuete(1, 'Quête haut niveau');
        $quete->setMinimalLevel(10);
        $pnj = $this->makePnjWithQuete($quete);

        $this->userQueteRepository->method('findOneBy')->willReturn(null);
        $this->niveauJoueurRepository->method('getPlayerLevel')->willReturn(1);
        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->service->startQuest($this->makeUser(1), $pnj);

        $this->assertSame('locked', $result['status']);
        $this->assertStringContainsString('Niveau 10 requis', $result['blockedMessages'][0]);
    }

    public function testStartQuestCreeLaProgressionSurLaPremiereSequence(): void
    {
        $quete = $this->makeQuete(1, 'Choix de la classe');
        $pnj = $this->makePnjWithQuete($quete);
        $firstSequence = $this->makeSequence(11, $quete, 1);

        $this->userQueteRepository->method('findOneBy')->willReturn(null);
        $this->sequenceRepository->method('findOneBy')
            ->with(['quete' => $quete, 'position' => 1])
            ->willReturn($firstSequence);
        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(UserQuete::class));

        $result = $this->service->startQuest($this->makeUser(1), $pnj);

        $this->assertSame('step', $result['status']);
        $this->assertSame(11, $result['step']['sequenceId']);
    }

    public function testStartQuestDejaCommenceeNeCreePasDeDoublon(): void
    {
        $quete = $this->makeQuete(1, 'Choix de la classe');
        $pnj = $this->makePnjWithQuete($quete);
        $sequence = $this->makeSequence(11, $quete, 1);

        $userQuete = new UserQuete();
        $userQuete->setSequence($sequence);
        $userQuete->setIsDone(false);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);
        $this->entityManager->expects($this->never())->method('persist');

        $result = $this->service->startQuest($this->makeUser(1), $pnj);

        $this->assertSame('step', $result['status']);
        $this->assertSame(11, $result['step']['sequenceId']);
    }

    /* ---------------------------------------------------------------- */
    /* Garde-fous d'exécution                                            */
    /* ---------------------------------------------------------------- */

    public function testExecuteActionRefuseUneActionHorsSequence(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));
        $this->sequenceRepository->method('find')->willReturn($sequence);

        $this->expectException(QuestException::class);
        $this->expectExceptionMessage("n'appartient pas");
        $this->service->executeAction($this->makeUser(1), 11, 999);
    }

    public function testExecuteActionRefuseSiCeNestPasLEtapeCourante(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $autreSequence = $this->makeSequence(12, $quete, 2);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));
        $this->sequenceRepository->method('find')->willReturn($sequence);

        $userQuete = new UserQuete();
        $userQuete->setSequence($autreSequence);
        $userQuete->setIsDone(false);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);

        $this->expectException(QuestException::class);
        $this->expectExceptionMessage('étape courante');
        $this->service->executeAction($this->makeUser(1), 11, 5);
    }

    public function testExecuteActionRejetteLesTypesReserves(): void
    {
        // CHOIX puis BATTRE_MONSTRE ont été implémentés ; KILL_PVP reste le dernier
        // type réservé, et le dispatcher doit continuer de le rejeter franchement
        // plutôt que de le laisser passer sans vérifier quoi que ce soit.
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::KILL_PVP));
        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));

        $this->expectException(UnsupportedQuestActionException::class);
        $this->service->executeAction($this->makeUser(1), 11, 5);
    }

    /* ---------------------------------------------------------------- */
    /* Conditions                                                        */
    /* ---------------------------------------------------------------- */

    public function testExecuteActionBloqueSiOrInsuffisant(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::DONNER_OR);
        $action->setQuantity(100);
        $this->attachAction($sequence, $action);
        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));

        $user = $this->makeUser(1);
        $user->setMoney(10);

        $result = $this->service->executeAction($user, 11, 5);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(["Vous n'avez pas assez d'or."], $result['blockedMessages']);
        $this->assertSame(10, $user->getMoney(), "L'or ne doit pas être consommé quand la condition échoue");
    }

    public function testLeMessagePersonnaliseDeLActionEstUtiliseQuandBloque(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::DONNER_OR);
        $action->setQuantity(100);
        $action->setMessage("Reviens quand tu auras de quoi payer, gamin.");
        $this->attachAction($sequence, $action);
        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));

        $user = $this->makeUser(1);
        $user->setMoney(10);

        $result = $this->service->executeAction($user, 11, 5);

        $this->assertSame(["Reviens quand tu auras de quoi payer, gamin."], $result['blockedMessages']);
    }

    public function testDonnerOrConsommeLOrQuandLaConditionPasse(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $nextSequence = $this->makeSequence(12, $quete, 2);
        $action = $this->makeAction(5, ActionType::DONNER_OR);
        $action->setQuantity(100);
        $this->attachAction($sequence, $action);

        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->sequenceRepository->method('findOneBy')
            ->with(['quete' => $quete, 'position' => 2])
            ->willReturn($nextSequence);
        $userQuete = $this->makeUserQueteOn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);

        $user = $this->makeUser(1);
        $user->setMoney(150);

        $result = $this->service->executeAction($user, 11, 5);

        $this->assertSame('step', $result['status']);
        $this->assertSame(50, $user->getMoney());
        $this->assertTrue($result['needRefresh'], "Une consommation doit rafraîchir l'état du joueur");
    }

    /* ---------------------------------------------------------------- */
    /* Avancement et fin                                                 */
    /* ---------------------------------------------------------------- */

    public function testPasserDialogueAvanceALaSequenceSuivante(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $nextSequence = $this->makeSequence(12, $quete, 2);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));

        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->sequenceRepository->method('findOneBy')->willReturn($nextSequence);
        $userQuete = $this->makeUserQueteOn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);

        $result = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame('step', $result['status']);
        $this->assertSame(12, $result['step']['sequenceId']);
        $this->assertSame($nextSequence, $userQuete->getSequence());
        $this->assertFalse($userQuete->getIsDone());
    }

    public function testUnChoixBrancheVersLaSequenceCibleeAuLieuDuLineaire(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $lineaire = $this->makeSequence(12, $quete, 2);   // position + 1 (à ignorer)
        $cible = $this->makeSequence(20, $quete, 5);       // cible du branchement
        $action = $this->makeAction(5, ActionType::CHOIX);
        $action->setNextSequence($cible);
        $this->attachAction($sequence, $action);

        $this->sequenceRepository->method('find')->willReturn($sequence);
        // Même si le linéaire existe, le branchement doit primer.
        $this->sequenceRepository->method('findOneBy')->willReturn($lineaire);
        $userQuete = $this->makeUserQueteOn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);

        $result = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame('step', $result['status']);
        $this->assertSame(20, $result['step']['sequenceId']);
        $this->assertSame($cible, $userQuete->getSequence());
        $this->assertFalse($userQuete->getIsDone());
    }

    public function testUnChoixEndsQuestTermineLaQueteMemeSiUneSuiteExiste(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $suite = $this->makeSequence(12, $quete, 2);
        $action = $this->makeAction(5, ActionType::CHOIX);
        $action->setEndsQuest(true);
        $this->attachAction($sequence, $action);

        $this->sequenceRepository->method('find')->willReturn($sequence);
        // Une suite linéaire existe mais endsQuest doit terminer la quête.
        $this->sequenceRepository->method('findOneBy')->willReturn($suite);
        $userQuete = $this->makeUserQueteOn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);

        $result = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame('done', $result['status']);
        $this->assertTrue($userQuete->getIsDone());
    }

    public function testLaDerniereSequenceTermineLaQuete(): void
    {
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 2);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));

        $this->sequenceRepository->method('find')->willReturn($sequence);
        // Pas de séquence à position 3 : fin de quête.
        $this->sequenceRepository->method('findOneBy')->willReturn(null);
        $userQuete = $this->makeUserQueteOn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);

        $result = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame('done', $result['status']);
        $this->assertTrue($userQuete->getIsDone());
    }

    public function testLaRecompenseObjetVaDansLInventaireObjets(): void
    {
        // Régression : l'ancien QuestService versait les objets dans
        // l'inventaire des consommables (QuestService::giveRecompenseToUser).
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::PASSER_DIALOGUE);

        $objet = $this->createConfiguredMock(\App\Entity\Objet::class, ['getId' => 42, 'getName' => 'Clé rouillée']);
        $recompense = new Recompense();
        $recompense->setObjet($objet);
        $recompense->setQuantity(2);
        $action->setRecompense($recompense);
        $this->attachAction($sequence, $action);

        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->sequenceRepository->method('findOneBy')->willReturn(null);
        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));

        $this->sacService->expects($this->once())
            ->method('ajouterItem')
            ->with($this->anything(), \App\Enum\TypeItem::OBJET, 42, 2);

        $result = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame([['type' => 'objet', 'id' => 42, 'label' => 'Clé rouillée', 'quantity' => 2]], $result['feedback']['rewards']);
    }

    /* ---------------------------------------------------------------- */
    /* Séquences sans quête (dialogues de PNJ "action")                  */
    /* ---------------------------------------------------------------- */

    public function testUneSequenceSansQueteExecuteSonEffetSansProgression(): void
    {
        $sequence = $this->makeSequence(3, null, 1);
        $pnj = new Pnj();
        $sequence->setPnj($pnj);
        $action = $this->makeAction(5, ActionType::SCRIPTED_EFFECT);
        $action->setEffect(\App\Enum\QuestEffect::ENTRER_AUBERGE);
        $this->attachAction($sequence, $action);

        $this->sequenceRepository->method('find')->willReturn($sequence);
        $user = $this->makeUser(1);
        $this->mockPnjAdjacentTo($user, $pnj, adjacent: true);
        $this->effectRegistry->expects($this->once())->method('execute')
            ->willReturn(['messages' => ["Vous entrez dans votre chambre d'auberge"], 'needRefresh' => true]);
        $this->userQueteRepository->expects($this->never())->method('findOneBy');

        $result = $this->service->executeAction($user, 3, 5);

        $this->assertSame('done', $result['status']);
        $this->assertTrue($result['needRefresh']);
        $this->assertSame("Vous entrez dans votre chambre d'auberge", $result['feedback']['messages'][0]['text']);
    }

    public function testUneSequenceSansQueteExigeLaProximiteDuPnj(): void
    {
        $sequence = $this->makeSequence(3, null, 1);
        $pnj = new Pnj();
        $sequence->setPnj($pnj);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));

        $this->sequenceRepository->method('find')->willReturn($sequence);
        $user = $this->makeUser(1);
        $this->mockPnjAdjacentTo($user, $pnj, adjacent: false);

        $this->expectException(QuestException::class);
        $this->expectExceptionMessage('trop loin');
        $this->service->executeAction($user, 3, 5);
    }

    /* ---------------------------------------------------------------- */
    /* Objectifs comptés (chasse, fabrication, récolte)                  */
    /* ---------------------------------------------------------------- */

    /**
     * LA régression à ne jamais réintroduire : un compteur cumulatif lu tel quel
     * validerait « tuez 3 loups » pour un vétéran qui en a tué cinquante avant même
     * d'avoir entendu la demande. C'est l'instantané pris au démarrage qui l'empêche.
     */
    public function testUnObjectifDeChasseNeCompteQueDepuisLeDebutDeLEtape(): void
    {
        $this->compteurs[TypeCompteur::MONSTRE_TUE->cle(7)] = 50;

        $quete = $this->makeQuete(1, 'Chasse');
        $pnj = $this->makePnjWithQuete($quete);
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::BATTRE_MONSTRE);
        $action->setMonstre($this->makeMonstre(7));
        $action->setQuantity(3);
        $this->attachAction($sequence, $action);

        $this->userQueteRepository->method('findOneBy')->willReturn(null);
        $this->sequenceRepository->method('findOneBy')->willReturn($sequence);

        $user = $this->makeUser(1);
        $demarrage = $this->service->startQuest($user, $pnj);

        // Le joueur repart de zéro sur cette étape, malgré ses 50 kills antérieurs.
        $this->assertSame(0, $demarrage['step']['actions'][0]['progress']['current']);
        $this->assertSame(3, $demarrage['step']['actions'][0]['progress']['target']);
    }

    public function testUnObjectifDeChasseBloqueTantQueLeCompteNYEstPas(): void
    {
        $this->compteurs[TypeCompteur::MONSTRE_TUE->cle(7)] = 50;

        $quete = $this->makeQuete(1, 'Chasse');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::BATTRE_MONSTRE);
        $action->setMonstre($this->makeMonstre(7));
        $action->setQuantity(3);
        $this->attachAction($sequence, $action);

        $userQuete = $this->makeUserQueteOn($sequence);
        $userQuete->setCompteursDepart([TypeCompteur::MONSTRE_TUE->cle(7) => 50]);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);
        $this->sequenceRepository->method('find')->willReturn($sequence);

        // Deux loups tués depuis la demande : il en manque un.
        $this->compteurs[TypeCompteur::MONSTRE_TUE->cle(7)] = 52;
        $bloque = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame('blocked', $bloque['status']);
        $this->assertStringContainsString('2 / 3', $bloque['blockedMessages'][0]);
        $this->assertSame(2, $bloque['step']['actions'][0]['progress']['current']);

        // Le troisième débloque l'étape.
        $this->compteurs[TypeCompteur::MONSTRE_TUE->cle(7)] = 53;
        $this->assertSame('done', $this->service->executeAction($this->makeUser(1), 11, 5)['status']);
    }

    /**
     * Une étape déjà entamée avant la mise en place des instantanés (départ absent)
     * doit se lire en cumulé plutôt que planter : dégradation lisible, jamais blocage.
     */
    public function testUnDepartAbsentSeLitEnCumule(): void
    {
        $this->compteurs[TypeCompteur::OBJET_FABRIQUE->cle(4)] = 2;

        $quete = $this->makeQuete(1, 'Artisan');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::FABRIQUER_OBJET);
        $action->setRecette($this->makeRecette(4));
        $action->setQuantity(2);
        $this->attachAction($sequence, $action);

        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));
        $this->sequenceRepository->method('find')->willReturn($sequence);

        $this->assertSame('done', $this->service->executeAction($this->makeUser(1), 11, 5)['status']);
    }

    /** Franchir une étape repose l'instantané pour les objectifs de la suivante. */
    public function testAvancerReposeLInstantaneDesCompteurs(): void
    {
        $this->compteurs[TypeCompteur::RESSOURCE_RECOLTEE->cle(9)] = 12;

        $quete = $this->makeQuete(1, 'Cueillette');
        $sequence = $this->makeSequence(11, $quete, 1);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));

        $suivante = $this->makeSequence(12, $quete, 2);
        $recolte = $this->makeAction(6, ActionType::RECOLTER_RESSOURCE);
        $recolte->setObjet($this->makeObjet(9));
        $recolte->setQuantity(4);
        $this->attachAction($suivante, $recolte);

        $userQuete = $this->makeUserQueteOn($sequence);
        $this->userQueteRepository->method('findOneBy')->willReturn($userQuete);
        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->sequenceRepository->method('findOneBy')->willReturn($suivante);

        $resultat = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame('step', $resultat['status']);
        $this->assertSame(12, $userQuete->getCompteurDepart(TypeCompteur::RESSOURCE_RECOLTEE->cle(9)));
        $this->assertSame(0, $resultat['step']['actions'][0]['progress']['current']);
    }

    /* ---------------------------------------------------------------- */
    /* Karma des choix                                                   */
    /* ---------------------------------------------------------------- */

    public function testUnChoixPeutCouterDuKarma(): void
    {
        $quete = $this->makeQuete(1, 'Dilemme');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::CHOIX);
        $action->setKarma(-30);
        $action->setEndsQuest(true);
        $this->attachAction($sequence, $action);

        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));
        $this->sequenceRepository->method('find')->willReturn($sequence);

        $user = $this->makeUser(1);
        $resultat = $this->service->executeAction($user, 11, 5);

        $this->assertSame(-30, $user->getKarma());
        $this->assertSame(-30, $resultat['karma']['delta']);
        $this->assertSame('Mesuré', $resultat['karma']['palier']);
        $this->assertStringContainsString('réputation', $resultat['feedback']['messages'][0]['text']);
    }

    /**
     * Une condition non remplie ne doit RIEN engager : ni ressource, ni réputation.
     * Le joueur n'a pas fait le choix, il a essayé de le faire.
     */
    public function testUneActionBloqueeNEngagePasLeKarma(): void
    {
        $quete = $this->makeQuete(1, 'Dilemme');
        $sequence = $this->makeSequence(11, $quete, 1);
        $action = $this->makeAction(5, ActionType::DONNER_OR);
        $action->setQuantity(100);
        $action->setKarma(50);
        $this->attachAction($sequence, $action);

        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));
        $this->sequenceRepository->method('find')->willReturn($sequence);

        $user = $this->makeUser(1); // 0 pièce d'or
        $resultat = $this->service->executeAction($user, 11, 5);

        $this->assertSame('blocked', $resultat['status']);
        $this->assertSame(0, $user->getKarma());
        $this->assertNull($resultat['karma']);
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                           */
    /* ---------------------------------------------------------------- */

    private function makeMonstre(int $id): Monstre
    {
        $monstre = new Monstre();
        $this->setId($monstre, $id);

        return $monstre;
    }

    private function makeRecette(int $id): Recette
    {
        $recette = new Recette();
        $this->setId($recette, $id);

        return $recette;
    }

    private function makeObjet(int $id): Objet
    {
        $objet = new Objet();
        $this->setId($objet, $id);

        return $objet;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $this->setId($user, $id);
        $user->setMoney(0);

        return $user;
    }

    private function makeQuete(int $id, string $name): Quete
    {
        $quete = new Quete();
        $this->setId($quete, $id);
        $quete->setName($name);

        return $quete;
    }

    private function makePnjWithQuete(Quete $quete): Pnj
    {
        $pnj = new Pnj();
        $pnj->setQuete($quete);

        return $pnj;
    }

    private function makeSequence(int $id, ?Quete $quete, int $position): Sequence
    {
        $sequence = new Sequence();
        $this->setId($sequence, $id);
        $sequence->setQuete($quete);
        $sequence->setPosition($position);
        $sequence->setName('seq' . $id);
        $sequence->setDialogueTitre('Titre');
        $sequence->setDialogueContenu("Ligne 1\nLigne 2");

        return $sequence;
    }

    private function makeAction(int $id, ActionType $type): Action
    {
        $action = new Action();
        $this->setId($action, $id);
        $action->setName('action' . $id);
        $action->setActionType($type);

        return $action;
    }

    private function attachAction(Sequence $sequence, Action $action): void
    {
        $sequenceAction = new SequenceAction();
        $sequenceAction->setAction($action);
        $sequenceAction->setPosition(1);
        $sequence->addSequenceAction($sequenceAction);
    }

    private function makeUserQueteOn(Sequence $sequence): UserQuete
    {
        $userQuete = new UserQuete();
        $userQuete->setSequence($sequence);
        $userQuete->setIsDone(false);

        return $userQuete;
    }

    /** Positionne le joueur en (5,5) carte 1, et le PNJ adjacent ou non. */
    private function mockPnjAdjacentTo(User $user, Pnj $pnj, bool $adjacent): void
    {
        $carte = new Carte();
        $this->setId($carte, 1);
        $user->setMap($carte);
        $user->setCaseAbscisse(5);
        $user->setCaseOrdonnee(5);

        $pnjCase = new CarteCarreau();
        $pnjCase->setCarte($carte);
        $pnjCase->setAbscisse($adjacent ? 6 : 20);
        $pnjCase->setOrdonnee(5);

        $this->carteCarreauRepository->method('findOneBy')
            ->with(['pnj' => $pnj])
            ->willReturn($pnjCase);
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
