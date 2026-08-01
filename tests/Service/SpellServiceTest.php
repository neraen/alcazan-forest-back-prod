<?php

namespace App\Tests\Service;

use App\Entity\Buff;
use App\Entity\Sortilege;
use App\Entity\User;
use App\Entity\UserBuff;
use App\Repository\BossRepository;
use App\Repository\BossSortilegeRepository;
use App\Repository\BuffCaracteristiqueRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\JoueurCaracteristiqueRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserBuffRepository;
use App\Repository\UserRepository;
use App\service\CaracteristiqueService;
use App\service\DeathService;
use App\service\SpellService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SpellServiceTest extends TestCase
{
    private UserRepository $userRepository;
    private UserBuffRepository $userBuffRepository;

    private function makeService(): SpellService
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userBuffRepository = $this->createMock(UserBuffRepository::class);

        return new SpellService(
            $this->createMock(DeathService::class),
            $this->createMock(CaracteristiqueService::class),
            $this->createMock(JoueurCaracteristiqueRepository::class),
            $this->createMock(JoueurCaracteristiqueBonusRepository::class),
            $this->createMock(NiveauJoueurRepository::class),
            $this->userRepository,
            $this->createMock(BossRepository::class),
            $this->createMock(BossSortilegeRepository::class),
            $this->createMock(UserBossRepository::class),
            $this->userBuffRepository,
            $this->createMock(BuffCaracteristiqueRepository::class),
            $this->createMock(\App\service\DonjonInstanceService::class),
            $this->createMock(\App\service\DonjonCombatService::class),
            $this->createMock(\App\service\JournalService::class),
            $this->createMock(\App\service\CumulJoueurService::class),
            $this->createMock(EntityManagerInterface::class)
        );
    }

    /** La réduction d'armure ne dépasse jamais 40 % et 0 armure = 0 réduction. */
    public function testComputeDamageWithArmorNoArmor(): void
    {
        $service = $this->makeService();
        $this->assertEqualsWithDelta(100.0, $service->computeDamageWithArmor(0, 100), 0.001);
    }

    public function testComputeDamageWithArmorIsCappedAt40Percent(): void
    {
        $service = $this->makeService();
        // Armure énorme : la réduction tend vers le plafond de 40 %
        $damage = $service->computeDamageWithArmor(100000, 100);
        $this->assertGreaterThanOrEqual(60.0, $damage);
        $this->assertEqualsWithDelta(60.0, $damage, 0.5);
    }

    public function testComputeDamageWithArmorReducesDamage(): void
    {
        $service = $this->makeService();
        $damage = $service->computeDamageWithArmor(400, 100);
        // (1 - 2.2^-1) * 0.4 ≈ 0.218 → ~78 points restants
        $this->assertLessThan(100.0, $damage);
        $this->assertGreaterThan(60.0, $damage);
    }

    /** Les dégâts d'un sort restent dans [min, max] défini par les caracs et coefs. */
    public function testGetSpellDamageByCaracStaysInBounds(): void
    {
        $service = $this->makeService();
        $spell = new Sortilege();
        $spell->setDegatBase(50);
        $spell->setCoefPrincipal(2.0);
        $spell->setCoefSecondaire(1.0);

        $min = 50 + 10 * 1.0;   // degatBase + secondaire * coefSecondaire
        $max = 50 + 30 * 2.0;   // degatBase + principale * coefPrincipal

        for ($i = 0; $i < 200; $i++) {
            $damage = $service->getSpellDamageByCarac($spell, 30, 10);
            $this->assertGreaterThanOrEqual($min, $damage);
            $this->assertLessThanOrEqual($max, $damage);
        }
    }

    /** Si min >= max (caracs déséquilibrées), le service retombe sur [max-20, max]. */
    public function testGetSpellDamageByCaracWhenMinExceedsMax(): void
    {
        $service = $this->makeService();
        $spell = new Sortilege();
        $spell->setDegatBase(50);
        $spell->setCoefPrincipal(1.0);
        $spell->setCoefSecondaire(3.0);

        $max = 50 + 10 * 1.0;
        for ($i = 0; $i < 100; $i++) {
            $damage = $service->getSpellDamageByCarac($spell, 10, 100);
            $this->assertGreaterThanOrEqual($max - 20, $damage);
            $this->assertLessThanOrEqual($max, $damage);
        }
    }

    /*
     * Les trois tests de bareme d'honneur ont MIGRE vers `HonneurServiceTest` (lot PvP).
     *
     * Ils eprouvaient `computeHonnorGain`/`computeHonnorLoose`, qui vivaient ici et
     * ecrivaient directement en base — l'honneur etait alors la seule valeur de progression
     * sans point de mutation unique. Ils passaient tous les trois alors meme que la formule
     * avait des trous (une difference de niveaux entre 30 et 50 rapportait le maximum) :
     * ils testaient trois POINTS, pas l'intervalle. `HonneurServiceTest` le balaie en entier.
     */

    /** Un buff ne peut être posé que s'il est absent ET que le joueur a < 3 buffs. */
    public function testPlayerCanBeBuffedWhenNotBuffedAndUnderLimit(): void
    {
        $service = $this->makeService();
        $buff = $this->createConfiguredMock(Buff::class, ['getId' => 1]);
        $user = $this->createConfiguredMock(User::class, ['getId' => 1]);

        $this->userBuffRepository->method('findOneBy')->willReturn(null);
        $this->userBuffRepository->method('findBy')->willReturn([]);

        $this->assertTrue($service->playerCanBeBuffed($buff, $user));
    }

    public function testPlayerCannotBeBuffedTwiceWithSameBuff(): void
    {
        $service = $this->makeService();
        $buff = $this->createConfiguredMock(Buff::class, ['getId' => 1]);
        $user = $this->createConfiguredMock(User::class, ['getId' => 1]);

        $this->userBuffRepository->method('findOneBy')->willReturn(new UserBuff());
        $this->userBuffRepository->method('findBy')->willReturn([new UserBuff()]);

        $this->assertFalse($service->playerCanBeBuffed($buff, $user));
    }

    public function testPlayerCannotHaveMoreThanThreeBuffs(): void
    {
        $service = $this->makeService();
        $buff = $this->createConfiguredMock(Buff::class, ['getId' => 1]);
        $user = $this->createConfiguredMock(User::class, ['getId' => 1]);

        $this->userBuffRepository->method('findOneBy')->willReturn(null);
        $this->userBuffRepository->method('findBy')
            ->willReturn([new UserBuff(), new UserBuff(), new UserBuff()]);

        $this->assertFalse($service->playerCanBeBuffed($buff, $user));
    }
}
