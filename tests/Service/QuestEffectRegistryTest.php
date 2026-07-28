<?php

namespace App\Tests\Service;

use App\Config\GameContent;
use App\Entity\Alignement;
use App\Entity\BossRecompense;
use App\Entity\Classe;
use App\Entity\Recompense;
use App\Entity\User;
use App\Entity\UserBoss;
use App\Enum\QuestEffect;
use App\Exception\QuestException;
use App\Repository\AlignementRepository;
use App\Repository\BossRecompenseRepository;
use App\Repository\ClasseRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserRepository;
use App\service\AubergeService;
use App\service\InventaireService;
use App\service\QuestEffectRegistry;
use App\service\RecompenseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * La whitelist des effets scriptés : chaque effet est exécuté côté serveur,
 * les paramètres invalides sont refusés bruyamment.
 */
class QuestEffectRegistryTest extends TestCase
{
    private ClasseRepository|MockObject $classeRepository;
    private UserRepository|MockObject $userRepository;
    private AlignementRepository|MockObject $alignementRepository;
    private BossRecompenseRepository|MockObject $bossRecompenseRepository;
    private UserBossRepository|MockObject $userBossRepository;
    private RecompenseService|MockObject $recompenseService;
    private InventaireService|MockObject $inventaireService;
    private AubergeService|MockObject $aubergeService;
    private QuestEffectRegistry $registry;

    protected function setUp(): void
    {
        $this->classeRepository = $this->createMock(ClasseRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->alignementRepository = $this->createMock(AlignementRepository::class);
        $this->bossRecompenseRepository = $this->createMock(BossRecompenseRepository::class);
        $this->userBossRepository = $this->createMock(UserBossRepository::class);
        $this->recompenseService = $this->createMock(RecompenseService::class);
        $this->inventaireService = $this->createMock(InventaireService::class);
        $this->aubergeService = $this->createMock(AubergeService::class);

        $this->registry = new QuestEffectRegistry(
            $this->classeRepository,
            $this->userRepository,
            $this->alignementRepository,
            $this->bossRecompenseRepository,
            $this->userBossRepository,
            $this->createMock(\App\Repository\CarteCarreauRepository::class),
            $this->createMock(\App\service\DonjonInstanceService::class),
            $this->createMock(\App\service\DonjonCombatService::class),
            $this->createMock(\App\service\DonjonSalleService::class),
            $this->recompenseService,
            $this->inventaireService,
            $this->aubergeService,
            $this->createMock(EntityManagerInterface::class)
        );
    }

    public function testChoisirClasseRefuseUneClasseInconnue(): void
    {
        $this->expectException(QuestException::class);
        $this->registry->execute(QuestEffect::CHOISIR_CLASSE, ['classe' => 'paladin'], $this->makeUser(1));
    }

    public function testChoisirClasseAssigneLaClasseEtLEquipementDeDepart(): void
    {
        $classe = $this->createConfiguredMock(Classe::class, ['getId' => 3]);
        $this->classeRepository->method('findOneBy')->with(['nom' => 'archer'])->willReturn($classe);

        $this->userRepository->expects($this->once())->method('updateClasse')->with(3, 1);
        $this->inventaireService->expects($this->once())
            ->method('addEquipementToUserInventaire')
            ->with(1, GameContent::STARTING_EQUIPEMENT_ARCHER);

        $result = $this->registry->execute(QuestEffect::CHOISIR_CLASSE, ['classe' => 'archer'], $this->makeUser(1));

        $this->assertTrue($result['needRefresh']);
        $this->assertStringContainsString('archer', $result['messages'][0]);
    }

    public function testChoisirAlignementRefuseUnAlignementInconnu(): void
    {
        $this->alignementRepository->method('find')->willReturn(null);

        $this->expectException(QuestException::class);
        $this->registry->execute(QuestEffect::CHOISIR_ALIGNEMENT, ['alignement' => 999], $this->makeUser(1));
    }

    public function testChoisirAlignementAssigneLAlignement(): void
    {
        $alignement = $this->createConfiguredMock(Alignement::class, ['getNom' => 'Lumière']);
        $this->alignementRepository->method('find')->with(2)->willReturn($alignement);
        $user = $this->makeUser(1);

        $result = $this->registry->execute(QuestEffect::CHOISIR_ALIGNEMENT, ['alignement' => 2], $user);

        $this->assertSame($alignement, $user->getAlignement());
        $this->assertTrue($result['needRefresh']);
    }

    public function testEntrerAubergeDelegueAuService(): void
    {
        $user = $this->makeUser(1);
        $this->aubergeService->expects($this->once())->method('entrer')->with($user)
            ->willReturn("Vous entrez dans votre chambre d'auberge");

        $result = $this->registry->execute(QuestEffect::ENTRER_AUBERGE, [], $user);

        $this->assertSame(["Vous entrez dans votre chambre d'auberge"], $result['messages']);
        $this->assertTrue($result['needRefresh']);
    }

    public function testRecompenseBossRefuseUnBossInconnu(): void
    {
        $this->bossRecompenseRepository->method('findBy')->willReturn([]);

        $this->expectException(QuestException::class);
        $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 999], $this->makeUser(1));
    }

    public function testRecompenseBossDistribueLeButinEtMarqueLeRamassage(): void
    {
        $recompense = (new Recompense())->setMoney(2500)->setExperience(3200);
        $this->donneUneTableDeButin($recompense);
        $userBoss = $this->userBossKill(new \DateTime('now'));
        $user = $this->makeUser(1);

        $this->recompenseService->method('tirerDansTable')->willReturn($recompense);
        $this->recompenseService->expects($this->once())->method('distribuer')->with($user, $recompense)
            ->willReturn(['rewards' => [['type' => 'or', 'label' => "pièces d'or", 'quantity' => 2500]], 'playerXp' => null]);
        $this->recompenseService->method('decrireRecompenses')->willReturn(["Vous obtenez 2500 pièces d'or."]);

        $result = $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 1], $user);

        $this->assertSame(["Vous obtenez 2500 pièces d'or."], $result['messages']);
        $this->assertStringNotContainsString('<', implode(' ', $result['messages']));
        $this->assertNotNull($userBoss->getLastLoot(), "le ramassage doit être horodaté pour interdire un second passage");
    }

    public function testRecompenseBossRefuseSiLeBossNAJamaisEteTue(): void
    {
        $this->donneUneTableDeButin(new Recompense());
        $this->userBossRepository->method('findOneBy')->willReturn(null);

        $this->recompenseService->expects($this->never())->method('distribuer');

        $this->expectException(QuestException::class);
        $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 1], $this->makeUser(1));
    }

    public function testRecompenseBossRefuseUnSecondRamassageSurLeMemeKill(): void
    {
        $this->donneUneTableDeButin(new Recompense());
        $kill = new \DateTime('now');
        $userBoss = $this->userBossKill($kill);
        $userBoss->setLastLoot($kill);

        $this->recompenseService->expects($this->never())->method('distribuer');

        $this->expectException(QuestException::class);
        $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 1], $this->makeUser(1));
    }

    public function testRecompenseBossRefuseUnKillTropAncien(): void
    {
        $this->donneUneTableDeButin(new Recompense());
        $this->userBossKill(new \DateTime('-' . (GameContent::FENETRE_SALLE_TRESOR_SECONDES + 60) . ' seconds'));

        $this->recompenseService->expects($this->never())->method('distribuer');

        $this->expectException(QuestException::class);
        $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 1], $this->makeUser(1));
    }

    public function testRecompenseBossAnnonceUnCoffreVideQuandLeTirageNeDonneRien(): void
    {
        $this->donneUneTableDeButin(new Recompense());
        $this->userBossKill(new \DateTime('now'));
        $this->recompenseService->method('tirerDansTable')->willReturn(null);

        $this->recompenseService->expects($this->never())->method('distribuer');

        $result = $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 1], $this->makeUser(1));

        $this->assertStringContainsString('poussière', $result['messages'][0]);
    }

    private function donneUneTableDeButin(Recompense $recompense): void
    {
        $bossRecompense = $this->createConfiguredMock(BossRecompense::class, ['getRecompense' => $recompense]);
        $this->bossRecompenseRepository->method('findBy')->willReturn([$bossRecompense]);
    }

    private function userBossKill(\DateTimeInterface $lastKill): UserBoss
    {
        $userBoss = (new UserBoss())->setLastKill($lastKill);
        $this->userBossRepository->method('findOneBy')->willReturn($userBoss);

        return $userBoss;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $property = new \ReflectionProperty($user, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
