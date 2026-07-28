<?php

namespace App\Tests\Service;

use App\Config\ArtisanatConfig;
use App\Entity\User;
use App\service\KarmaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Karma : bornes, paliers et contrat de transaction. Aucune base nécessaire.
 */
class KarmaServiceTest extends TestCase
{
    private EntityManagerInterface|MockObject $entityManager;
    private KarmaService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new KarmaService($this->entityManager);
    }

    public function testUnNouveauPersonnageEstNeutre(): void
    {
        $etat = $this->service->decrire(new User());

        $this->assertSame(0, $etat['valeur']);
        $this->assertSame('Mesuré', $etat['palier']);
    }

    public function testAjusterMonteEtDescendLeKarma(): void
    {
        $user = new User();

        $this->assertSame(30, $this->service->ajuster($user, 30)['karma']);
        $this->assertSame(10, $this->service->ajuster($user, -20)['karma']);
        $this->assertSame(10, $user->getKarma());
    }

    public function testLeKarmaEstBorneEnHaut(): void
    {
        $user = (new User())->setKarma(ArtisanatConfig::KARMA_MAX - 5);

        $resultat = $this->service->ajuster($user, 1000);

        $this->assertSame(ArtisanatConfig::KARMA_MAX, $resultat['karma']);
        $this->assertSame(5, $resultat['delta'], "Le delta annoncé est celui RÉELLEMENT appliqué");
    }

    public function testLeKarmaEstBorneEnBas(): void
    {
        $user = (new User())->setKarma(ArtisanatConfig::KARMA_MIN + 2);

        $resultat = $this->service->ajuster($user, -1000);

        $this->assertSame(ArtisanatConfig::KARMA_MIN, $resultat['karma']);
        $this->assertSame(-2, $resultat['delta']);
    }

    /**
     * À la borne, l'ajustement ne fait rien : l'appelant doit pouvoir s'en apercevoir
     * plutôt qu'annoncer au joueur un gain qui n'a pas eu lieu.
     */
    public function testALaBorneLeDeltaEstNul(): void
    {
        $user = (new User())->setKarma(ArtisanatConfig::KARMA_MAX);

        $resultat = $this->service->ajuster($user, 50);

        $this->assertSame(0, $resultat['delta']);
        $this->assertSame(ArtisanatConfig::KARMA_MAX, $user->getKarma());
    }

    /** Même contrat que SacService : la transaction appartient à l'appelant. */
    public function testLeServiceNeFlushePas(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $this->service->ajuster(new User(), 100);
    }

    public function testLesPaliersVontDuPillardAuGardien(): void
    {
        $this->assertSame('Pillard', ArtisanatConfig::palierKarma(ArtisanatConfig::KARMA_MIN));
        $this->assertSame('Rapace', ArtisanatConfig::palierKarma(-400));
        $this->assertSame('Mesuré', ArtisanatConfig::palierKarma(0));
        $this->assertSame('Prévoyant', ArtisanatConfig::palierKarma(400));
        $this->assertSame('Gardien', ArtisanatConfig::palierKarma(ArtisanatConfig::KARMA_MAX));
    }

    /** Un seuil appartient au palier qu'il ouvre, pas au précédent. */
    public function testLesSeuilsAppartiennentAuPalierQuIlsOuvrent(): void
    {
        $this->assertSame('Rapace', ArtisanatConfig::palierKarma(-600));
        $this->assertSame('Pillard', ArtisanatConfig::palierKarma(-601));
        $this->assertSame('Mesuré', ArtisanatConfig::palierKarma(-200));
        $this->assertSame('Prévoyant', ArtisanatConfig::palierKarma(200));
        $this->assertSame('Gardien', ArtisanatConfig::palierKarma(600));
    }

    /** La bande neutre est centrée sur 0 : on ne naît ni vertueux ni coupable. */
    public function testLaBandeNeutreEstCentreeSurZero(): void
    {
        $this->assertSame('Mesuré', ArtisanatConfig::palierKarma(-199));
        $this->assertSame('Mesuré', ArtisanatConfig::palierKarma(199));
    }

    /** Un karma sous le plancher (donnée corrompue) reste lisible, pas d'index absent. */
    public function testUnKarmaHorsBornesResteQualifiable(): void
    {
        $this->assertSame('Pillard', ArtisanatConfig::palierKarma(-99999));
        $this->assertSame('Gardien', ArtisanatConfig::palierKarma(99999));
    }
}
