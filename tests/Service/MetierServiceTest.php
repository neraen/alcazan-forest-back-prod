<?php

namespace App\Tests\Service;

use App\Entity\JoueurMetier;
use App\Entity\Metier;
use App\Entity\User;
use App\Enum\FamilleMetier;
use App\Exception\MetierException;
use App\Repository\JoueurMetierRepository;
use App\Repository\MetierRepository;
use App\service\MetierService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Métiers : apprentissage, plafonds par famille et courbe de progression — tout ce qui
 * s'isole sans base.
 */
class MetierServiceTest extends TestCase
{
    private JoueurMetierRepository|MockObject $joueurMetierRepository;
    private MetierService $service;

    protected function setUp(): void
    {
        $this->joueurMetierRepository = $this->createMock(JoueurMetierRepository::class);
        $this->service = new MetierService(
            $this->joueurMetierRepository,
            $this->createMock(MetierRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Apprentissage                                                       */
    /* ------------------------------------------------------------------ */

    public function testUnMetierNonApprisEstAuNiveauZero(): void
    {
        $this->joueurMetierRepository->method('findOneBy')->willReturn(null);

        $this->assertSame(0, $this->service->niveau(new User(), new Metier()));
        $this->assertFalse($this->service->estAppris(new User(), new Metier()));
    }

    public function testApprendreUnMetierLeMetAuNiveauUn(): void
    {
        $this->joueurMetierRepository->method('findOneBy')->willReturn(null);
        $this->joueurMetierRepository->method('findBy')->willReturn([]);

        $resultat = $this->service->apprendre(new User(), $this->metier('Mineur'));

        $this->assertSame(1, $resultat['niveau']);
        $this->assertSame(0, $resultat['experience']);
        $this->assertSame('recolte', $resultat['famille']);
    }

    public function testApprendreDeuxFoisLeMemeMetierEstRefuse(): void
    {
        $metier = $this->metier('Mineur');
        $this->joueurMetierRepository->method('findOneBy')->willReturn($this->progression($metier, 1, 0));

        $this->expectException(MetierException::class);
        $this->service->apprendre(new User(), $metier);
    }

    /**
     * Le cœur du lot : sans plafond applicable, la limite « 2 récolte / 3 fabrication »
     * du game design n'existerait que sur le papier.
     */
    public function testLeTroisiemeMetierDeRecolteEstRefuse(): void
    {
        $this->joueurMetierRepository->method('findOneBy')->willReturn(null);
        $this->joueurMetierRepository->method('findBy')->willReturn([
            $this->progression($this->metier('Mineur'), 1, 0),
            $this->progression($this->metier('Herboriste'), 1, 0),
        ]);

        $this->expectException(MetierException::class);
        $this->expectExceptionMessageMatches('/2 métiers de récolte/');
        $this->service->apprendre(new User(), $this->metier('Bûcheron'));
    }

    /** Les familles se comptent séparément : deux récoltes n'empêchent pas une fabrication. */
    public function testLesPlafondsSontIndependantsParFamille(): void
    {
        $this->joueurMetierRepository->method('findOneBy')->willReturn(null);
        $this->joueurMetierRepository->method('findBy')->willReturn([
            $this->progression($this->metier('Mineur'), 1, 0),
            $this->progression($this->metier('Herboriste'), 1, 0),
        ]);

        $resultat = $this->service->apprendre(new User(), $this->metier('Forgeron', FamilleMetier::CRAFT));

        $this->assertSame('craft', $resultat['famille']);
    }

    public function testLesPlacesRestantesTiennentCompteDesMetiersAppris(): void
    {
        $this->joueurMetierRepository->method('findBy')->willReturn([
            $this->progression($this->metier('Mineur'), 1, 0),
            $this->progression($this->metier('Forgeron', FamilleMetier::CRAFT), 1, 0),
        ]);

        $places = $this->service->placesRestantes(new User());

        $this->assertSame(1, $places['recolte']);
        $this->assertSame(2, $places['craft']);
    }

    public function testOublierUnMetierNonApprisEstRefuse(): void
    {
        $this->joueurMetierRepository->method('findOneBy')->willReturn(null);

        $this->expectException(MetierException::class);
        $this->service->oublier(new User(), $this->metier('Mineur'));
    }

    /* ------------------------------------------------------------------ */
    /* Progression                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Le garde-fou qui rend le plafond réel : sans lui, une case de récolte mal
     * configurée rendrait mineur un joueur qui n'a jamais vu de maître.
     */
    public function testGagnerDeLExperienceSurUnMetierNonApprisEstRefuse(): void
    {
        $this->joueurMetierRepository->method('findOneBy')->willReturn(null);

        $this->expectException(MetierException::class);
        $this->expectExceptionMessageMatches('/auprès d\'un maître/');
        $this->service->gagnerExperience(new User(), $this->metier('Mineur'), 30);
    }

    public function testLaCourbeCommenceAZeroPourLeNiveauUn(): void
    {
        $this->assertSame(0, MetierService::experiencePourNiveau(1));
        $this->assertSame(100, MetierService::experiencePourNiveau(2));
        $this->assertGreaterThan(
            MetierService::experiencePourNiveau(2),
            MetierService::experiencePourNiveau(3),
            'La courbe doit être strictement croissante'
        );
    }

    public function testAtteindreLePalierFaitMonterDeNiveau(): void
    {
        $metier = $this->metier('Herboriste');
        $this->joueurMetierRepository->method('findOneBy')->willReturn($this->progression($metier, 1, 90));

        $resultat = $this->service->gagnerExperience(new User(), $metier, 20);

        $this->assertSame(2, $resultat['niveau']);
        $this->assertSame(1, $resultat['niveauxGagnes']);
    }

    /** Un gain massif ne doit pas être écrêté à un seul palier. */
    public function testUnGrosGainFaitMonterDePlusieursNiveaux(): void
    {
        $metier = $this->metier('Herboriste');
        $this->joueurMetierRepository->method('findOneBy')->willReturn($this->progression($metier, 1, 0));

        $resultat = $this->service->gagnerExperience(new User(), $metier, 5000);

        $this->assertGreaterThan(2, $resultat['niveau']);
        $this->assertSame($resultat['niveau'] - 1, $resultat['niveauxGagnes']);
    }

    public function testLeNiveauMaxDuMetierPlafonneLaProgression(): void
    {
        $metier = $this->metier('Herboriste', niveauMax: 3);
        $this->joueurMetierRepository->method('findOneBy')->willReturn($this->progression($metier, 1, 0));

        $resultat = $this->service->gagnerExperience(new User(), $metier, 1000000);

        $this->assertSame(3, $resultat['niveau']);
    }

    /** Le plafond de contenu monte à 200 : la courbe doit rester atteignable jusque-là. */
    public function testLaProgressionVaJusquAuNiveauDeuxCents(): void
    {
        $metier = $this->metier('Herboriste', niveauMax: 200);
        $this->joueurMetierRepository->method('findOneBy')->willReturn($this->progression($metier, 1, 0));

        $resultat = $this->service->gagnerExperience(new User(), $metier, PHP_INT_MAX >> 4);

        $this->assertSame(200, $resultat['niveau']);
        $this->assertSame(MetierService::experiencePourNiveau(201), $resultat['experienceProchainNiveau']);
    }

    /* ------------------------------------------------------------------ */

    private function metier(string $nom, FamilleMetier $famille = FamilleMetier::RECOLTE, int $niveauMax = 100): Metier
    {
        return (new Metier())->setNom($nom)->setFamille($famille)->setNiveauMax($niveauMax);
    }

    private function progression(Metier $metier, int $niveau, int $experience): JoueurMetier
    {
        return (new JoueurMetier())->setMetier($metier)->setNiveau($niveau)->setExperience($experience);
    }
}
