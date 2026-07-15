<?php

namespace App\Tests\Service;

use App\Entity\Action;
use App\Entity\Carte;
use App\Entity\CarteCarreau;
use App\Entity\Pnj;
use App\Entity\Quete;
use App\Entity\Recompense;
use App\Entity\Sequence;
use App\Entity\SequenceAction;
use App\Entity\User;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Exception\QuestException;
use App\Exception\UnsupportedQuestActionException;
use App\Repository\ActionRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserQueteRepository;
use App\service\InventaireService;
use App\service\LevelingService;
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
    private InventaireRepository|MockObject $inventaireRepository;
    private InventaireObjetRepository|MockObject $inventaireObjetRepository;
    private InventaireEquipementRepository|MockObject $inventaireEquipementRepository;
    private InventaireConsommableRepository|MockObject $inventaireConsommableRepository;
    private NiveauJoueurRepository|MockObject $niveauJoueurRepository;
    private UserBossRepository|MockObject $userBossRepository;
    private CarteCarreauRepository|MockObject $carteCarreauRepository;
    private InventaireService|MockObject $inventaireService;
    private LevelingService|MockObject $levelingService;
    private QuestEffectRegistry|MockObject $effectRegistry;
    /* EntityManager concret : wrapInTransaction n'existe sur l'interface
       que par annotation @method (ORM 2.x), donc non mockable dessus. */
    private EntityManager|MockObject $entityManager;
    private QuestProgressionService $service;

    protected function setUp(): void
    {
        $this->sequenceRepository = $this->createMock(SequenceRepository::class);
        $this->userQueteRepository = $this->createMock(UserQueteRepository::class);
        $this->actionRepository = $this->createMock(ActionRepository::class);
        $this->inventaireRepository = $this->createMock(InventaireRepository::class);
        $this->inventaireObjetRepository = $this->createMock(InventaireObjetRepository::class);
        $this->inventaireEquipementRepository = $this->createMock(InventaireEquipementRepository::class);
        $this->inventaireConsommableRepository = $this->createMock(InventaireConsommableRepository::class);
        $this->niveauJoueurRepository = $this->createMock(NiveauJoueurRepository::class);
        $this->userBossRepository = $this->createMock(UserBossRepository::class);
        $this->carteCarreauRepository = $this->createMock(CarteCarreauRepository::class);
        $this->inventaireService = $this->createMock(InventaireService::class);
        $this->levelingService = $this->createMock(LevelingService::class);
        $this->effectRegistry = $this->createMock(QuestEffectRegistry::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());

        $this->service = new QuestProgressionService(
            $this->sequenceRepository,
            $this->userQueteRepository,
            $this->actionRepository,
            $this->inventaireRepository,
            $this->inventaireObjetRepository,
            $this->inventaireEquipementRepository,
            $this->inventaireConsommableRepository,
            $this->niveauJoueurRepository,
            $this->userBossRepository,
            $this->carteCarreauRepository,
            $this->inventaireService,
            $this->levelingService,
            $this->effectRegistry,
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
        $quete = $this->makeQuete(1, 'Q');
        $sequence = $this->makeSequence(11, $quete, 1);
        $this->attachAction($sequence, $this->makeAction(5, ActionType::CHOIX));
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
        $this->attachAction($sequence, $this->makeAction(5, ActionType::PASSER_DIALOGUE));

        $objet = $this->createConfiguredMock(\App\Entity\Objet::class, ['getId' => 42, 'getName' => 'Clé rouillée']);
        $recompense = new Recompense();
        $recompense->setObjet($objet);
        $recompense->setQuantity(2);
        $sequence->setRecompense($recompense);

        $this->sequenceRepository->method('find')->willReturn($sequence);
        $this->sequenceRepository->method('findOneBy')->willReturn(null);
        $this->userQueteRepository->method('findOneBy')->willReturn($this->makeUserQueteOn($sequence));

        $this->inventaireService->expects($this->once())
            ->method('addObjetToUserInventaire')->with(1, 42, 2);
        $this->inventaireService->expects($this->never())
            ->method('addConsommableToUserInventaire');

        $result = $this->service->executeAction($this->makeUser(1), 11, 5);

        $this->assertSame([['type' => 'objet', 'label' => 'Clé rouillée', 'quantity' => 2]], $result['feedback']['rewards']);
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
    /* Helpers                                                           */
    /* ---------------------------------------------------------------- */

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
