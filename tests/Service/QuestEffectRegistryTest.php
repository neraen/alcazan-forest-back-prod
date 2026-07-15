<?php

namespace App\Tests\Service;

use App\Config\GameContent;
use App\Entity\Alignement;
use App\Entity\BossRecompense;
use App\Entity\Classe;
use App\Entity\Recompense;
use App\Entity\User;
use App\Enum\QuestEffect;
use App\Exception\QuestException;
use App\Repository\AlignementRepository;
use App\Repository\BossRecompenseRepository;
use App\Repository\ClasseRepository;
use App\Repository\UserRepository;
use App\service\AubergeService;
use App\service\InventaireService;
use App\service\QuestEffectRegistry;
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
    private InventaireService|MockObject $inventaireService;
    private AubergeService|MockObject $aubergeService;
    private QuestEffectRegistry $registry;

    protected function setUp(): void
    {
        $this->classeRepository = $this->createMock(ClasseRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->alignementRepository = $this->createMock(AlignementRepository::class);
        $this->bossRecompenseRepository = $this->createMock(BossRecompenseRepository::class);
        $this->inventaireService = $this->createMock(InventaireService::class);
        $this->aubergeService = $this->createMock(AubergeService::class);

        $this->registry = new QuestEffectRegistry(
            $this->classeRepository,
            $this->userRepository,
            $this->alignementRepository,
            $this->bossRecompenseRepository,
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

    public function testRecompenseBossConstruitLesMessagesSansHtml(): void
    {
        $recompense = new Recompense();
        $recompense->setMoney(2500);
        $recompense->setExperience(3200);
        $bossRecompense = $this->createConfiguredMock(BossRecompense::class, ['getRecompense' => $recompense]);
        $this->bossRecompenseRepository->method('findBy')->willReturn([$bossRecompense]);

        $result = $this->registry->execute(QuestEffect::RECOMPENSE_BOSS, ['bossId' => 1], $this->makeUser(1));

        $this->assertSame("Vous gagnez 2500 pièces d'or.", $result['messages'][0]);
        $this->assertSame("Vous gagnez 3200 points d'expérience.", $result['messages'][1]);
        $this->assertStringNotContainsString('<', implode(' ', $result['messages']));
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $property = new \ReflectionProperty($user, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
