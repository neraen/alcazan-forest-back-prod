<?php

namespace App\Tests\Service;

use App\Entity\Boss;
use App\Entity\Donjon;
use App\Entity\DonjonInstance;
use App\Entity\DonjonInstanceMembre;
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
use App\service\DeathService;
use App\service\DonjonCombatService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Les règles de combat qu'on peut isoler sans base : garde-fous d'attaque, table de
 * menace, fenêtres de phase et chronomètre d'enrage. Le reste (zones, renforts) est
 * couvert par DonjonApiFunctionalTest, qui a besoin de vraies cases.
 */
class DonjonCombatServiceTest extends TestCase
{
    private DonjonMecaniqueRepository|MockObject $mecaniqueRepository;
    private CarteCarreauRepository|MockObject $carteCarreauRepository;
    private DonjonCombatService $service;

    protected function setUp(): void
    {
        $this->mecaniqueRepository = $this->createMock(DonjonMecaniqueRepository::class);
        $this->carteCarreauRepository = $this->createMock(CarteCarreauRepository::class);

        $this->service = new DonjonCombatService(
            $this->mecaniqueRepository,
            $this->createMock(DonjonInstanceZoneRepository::class),
            $this->createMock(DonjonInstanceMonstreRepository::class),
            $this->createMock(DonjonInstanceLevierRepository::class),
            $this->carteCarreauRepository,
            $this->createMock(MonstreRepository::class),
            $this->createMock(DeathService::class),
            $this->createMock(EntityManagerInterface::class)
        );
    }

    /* ---------------------------------------------------------------- */
    /* Garde-fous d'attaque                                              */
    /* ---------------------------------------------------------------- */

    public function testUneAttaqueSansAssezDePointsDActionEstRefusee(): void
    {
        $user = $this->joueur(1, pointsAction: 2);
        $spell = $this->sort(pointAction: 5, portee: 10);

        $this->expectException(DonjonException::class);
        $this->expectExceptionMessageMatches('/points d\'action/');
        $this->service->verifierAttaqueBoss($user, $this->boss(), $spell);
    }

    public function testUneAttaqueHorsDePorteeEstRefusee(): void
    {
        $user = $this->joueur(1, abscisse: 0, ordonnee: 0, carteId: 11);
        $this->poserLeBossEn(11, 8, carteId: 11);

        $this->expectException(DonjonException::class);
        $this->expectExceptionMessageMatches('/hors de portée/');
        $this->service->verifierAttaqueBoss($user, $this->boss(), $this->sort(portee: 3));
    }

    public function testUneAttaqueDepuisUneAutreCarteEstRefusee(): void
    {
        $user = $this->joueur(1, abscisse: 11, ordonnee: 8, carteId: 9);
        $this->poserLeBossEn(11, 8, carteId: 11);

        $this->expectException(DonjonException::class);
        $this->expectExceptionMessageMatches("/n'est pas sur cette carte/");
        $this->service->verifierAttaqueBoss($user, $this->boss(), $this->sort(portee: 10));
    }

    public function testUneAttaqueAPorteeEstAcceptee(): void
    {
        $user = $this->joueur(1, abscisse: 11, ordonnee: 6, carteId: 11);
        $this->poserLeBossEn(11, 8, carteId: 11);

        $this->service->verifierAttaqueBoss($user, $this->boss(), $this->sort(portee: 3));

        $this->addToAssertionCount(1); // aucune exception = accepté
    }

    /* ---------------------------------------------------------------- */
    /* Menace                                                            */
    /* ---------------------------------------------------------------- */

    /** La règle qui fait exister le rôle de tank. */
    public function testLeBossFrappeLaPlusGrosseMenacePasLeDernierAttaquant(): void
    {
        $instance = $this->instance();
        $tank = $this->membre($instance, $this->joueur(1), menace: 900);
        $this->membre($instance, $this->joueur(2), menace: 120);

        $this->assertSame($tank, $this->service->cibleDuBoss($instance));
    }

    public function testUnMembreMortNEstPlusCible(): void
    {
        $instance = $this->instance();
        $mort = $this->joueur(1, vie: 0);
        $this->membre($instance, $mort, menace: 5000);
        $vivant = $this->joueur(2);
        $membreVivant = $this->membre($instance, $vivant, menace: 10);

        $this->assertSame($membreVivant, $this->service->cibleDuBoss($instance));
    }

    public function testUnMembreSortiNEstPlusCible(): void
    {
        $instance = $this->instance();
        $parti = $this->membre($instance, $this->joueur(1), menace: 5000);
        $parti->setPresent(false);
        $reste = $this->membre($instance, $this->joueur(2), menace: 10);

        $this->assertSame($reste, $this->service->cibleDuBoss($instance));
    }

    /**
     * Le boss ne voit que SA salle : un joueur reparti ailleurs — typiquement mort puis
     * revenu en salle 1 — ne doit plus être frappé ni servir de centre à ses zones, même
     * s'il porte toute la menace.
     */
    public function testLeBossIgnoreUnMembreQuiNEstPasDansSaSalle(): void
    {
        $instance = $this->instance();
        $this->poserLeBossEn(11, 8, carteId: 11);

        $parti = $this->membre($instance, $this->joueur(1, carteId: 8), menace: 5000);
        $reste = $this->membre($instance, $this->joueur(2, carteId: 11), menace: 10);

        $this->assertSame(
            $reste,
            $this->service->cibleDuBoss($instance, $this->boss()),
            "Seuls les membres présents dans la salle du boss sont ciblables"
        );
        $this->assertSame(
            $parti,
            $this->service->cibleDuBoss($instance),
            "Sans boss fourni, la menace pure fait foi (comportement historique)"
        );
    }

    public function testLeBossNAAucuneCibleQuandSaSalleEstVide(): void
    {
        $instance = $this->instance();
        $this->poserLeBossEn(11, 8, carteId: 11);
        $this->membre($instance, $this->joueur(1, carteId: 8), menace: 5000);

        $this->assertNull($this->service->cibleDuBoss($instance, $this->boss()));
    }

    public function testLesDegatsAlimentventLaMenace(): void
    {
        $instance = $this->instance();
        $joueur = $this->joueur(1);
        $membre = $this->membre($instance, $joueur, menace: 0);

        $this->service->ajouterMenaceDegats($instance, $joueur, 250);

        $this->assertSame(250, $membre->getMenace());
    }

    /** Un soigneur monte en menace, mais moins vite : il ne passe pas devant le tank. */
    public function testLeSoinGenereMoinsDeMenaceQueLesDegats(): void
    {
        $instance = $this->instance();
        $soigneur = $this->joueur(1);
        $membre = $this->membre($instance, $soigneur, menace: 0);

        $this->service->ajouterMenaceSoin($instance, $soigneur, 200);

        $this->assertSame(100, $membre->getMenace());
    }

    /* ---------------------------------------------------------------- */
    /* Phases                                                            */
    /* ---------------------------------------------------------------- */

    public function testUneMecaniqueNeSAppliqueQueDansSaFenetreDeVie(): void
    {
        $mecanique = (new DonjonMecanique())->setVieMax(75)->setVieMin(40);

        $this->assertFalse($mecanique->couvre(90), 'au-dessus de la fenêtre');
        $this->assertTrue($mecanique->couvre(75), 'borne haute incluse');
        $this->assertTrue($mecanique->couvre(60));
        $this->assertTrue($mecanique->couvre(40), 'borne basse incluse');
        $this->assertFalse($mecanique->couvre(20), 'en dessous de la fenêtre');
    }

    public function testUneMecaniqueDesactiveeNeSAppliqueJamais(): void
    {
        $mecanique = (new DonjonMecanique())->setVieMax(100)->setVieMin(0)->setActif(false);

        $this->assertFalse($mecanique->couvre(50));
    }

    /* ---------------------------------------------------------------- */
    /* Enrage                                                            */
    /* ---------------------------------------------------------------- */

    public function testPasDEnrageAvantLeDelai(): void
    {
        $instance = $this->instance();
        $instance->setCombatDebutAt(new \DateTimeImmutable('-2 minutes'));
        $this->donneLesMecaniques([$this->enrage(apresSecondes: 600, multiplicateur: 2.0)]);

        $this->assertSame(1.0, $this->service->multiplicateurEnrage($instance));
    }

    public function testEnrageApresLeDelai(): void
    {
        $instance = $this->instance();
        $instance->setCombatDebutAt(new \DateTimeImmutable('-11 minutes'));
        $this->donneLesMecaniques([$this->enrage(apresSecondes: 600, multiplicateur: 2.0)]);

        $this->assertSame(2.0, $this->service->multiplicateurEnrage($instance));
    }

    public function testPasDEnrageTantQueLeCombatNEstPasEngage(): void
    {
        $this->donneLesMecaniques([$this->enrage(apresSecondes: 1, multiplicateur: 3.0)]);

        $this->assertSame(1.0, $this->service->multiplicateurEnrage($this->instance()));
    }

    /* ---------------------------------------------------------------- */

    private function donneLesMecaniques(array $mecaniques): void
    {
        $this->mecaniqueRepository->method('findBy')->willReturn($mecaniques);
    }

    private function enrage(int $apresSecondes, float $multiplicateur): DonjonMecanique
    {
        return (new DonjonMecanique())
            ->setType(MecaniqueDonjon::ENRAGE)
            ->setParams(['apresSecondes' => $apresSecondes, 'multiplicateur' => $multiplicateur]);
    }

    private function poserLeBossEn(int $abscisse, int $ordonnee, int $carteId): void
    {
        $carte = $this->createConfiguredMock(\App\Entity\Carte::class, ['getId' => $carteId]);
        $case = $this->createConfiguredMock(\App\Entity\CarteCarreau::class, [
            'getAbscisse' => $abscisse,
            'getOrdonnee' => $ordonnee,
            'getCarte' => $carte,
        ]);
        $this->carteCarreauRepository->method('findOneBy')->willReturn($case);
    }

    private function boss(): Boss
    {
        return (new Boss())->setName('Grimbald')->setMaxLife(5000);
    }

    private function sort(int $pointAction = 1, int $portee = 5): Sortilege
    {
        return (new Sortilege())->setNom('Flèche')->setPointAction($pointAction)->setPortee($portee);
    }

    private function instance(): DonjonInstance
    {
        return (new DonjonInstance())->setDonjon(new Donjon());
    }

    private function joueur(
        int $id,
        int $vie = 400,
        int $pointsAction = 100,
        int $abscisse = 0,
        int $ordonnee = 0,
        ?int $carteId = null
    ): User {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id);
        $user->setCurrentLife($vie);
        $user->setActionPoint($pointsAction);
        $user->setCaseAbscisse($abscisse);
        $user->setCaseOrdonnee($ordonnee);
        if ($carteId !== null) {
            $user->setMap($this->createConfiguredMock(\App\Entity\Carte::class, ['getId' => $carteId]));
        }

        return $user;
    }

    private function membre(DonjonInstance $instance, User $user, int $menace): DonjonInstanceMembre
    {
        $membre = (new DonjonInstanceMembre())->setUser($user)->setMenace($menace);
        $instance->addMembre($membre);

        return $membre;
    }
}
