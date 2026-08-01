<?php

namespace App\Tests\Service;

use App\Config\PvpConfig;
use App\Entity\User;
use App\Repository\EvenementJeuRepository;
use App\service\HonneurService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * L'honneur, et surtout sa FORMULE.
 *
 * `testLaFormuleNaAucunTrou` est le test qui aurait attrapé le bug d'origine : l'ancienne
 * chaîne de six `if/else` laissait une différence de niveaux entre 30 et 50 — ou égale à
 * 9, 18 ou 30 — tomber dans le `else` final et rapporter le MAXIMUM pour avoir tué quelqu'un
 * très en dessous de soi. Une droite bornée ne peut pas avoir ce défaut ; encore faut-il le
 * vérifier plutôt que de l'affirmer.
 */
class HonneurServiceTest extends TestCase
{
    private EvenementJeuRepository|MockObject $evenementRepository;
    private HonneurService $service;

    protected function setUp(): void
    {
        $this->evenementRepository = $this->createMock(EvenementJeuRepository::class);
        $this->service = new HonneurService(
            $this->createMock(EntityManager::class),
            $this->evenementRepository
        );
    }

    /* ---------------------------------------------------------------- */
    /* La formule                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Sur tout l'intervalle utile, le gain est MONOTONE (battre plus fort rapporte plus ou
     * autant) et toujours dans ses bornes. C'est ce que la chaîne de branches ne garantissait
     * pas : elle avait des sauts et des retours en arrière.
     */
    public function testLaFormuleNaAucunTrou(): void
    {
        $precedent = null;

        for ($ecart = -200; $ecart <= 200; ++$ecart) {
            $gain = PvpConfig::gainVainqueur(50, 50 + $ecart);

            $this->assertGreaterThanOrEqual(PvpConfig::HONNEUR_GAIN_MIN, $gain, "écart $ecart");
            $this->assertLessThanOrEqual(PvpConfig::HONNEUR_GAIN_MAX, $gain, "écart $ecart");

            if ($precedent !== null) {
                $this->assertGreaterThanOrEqual(
                    $precedent,
                    $gain,
                    "Le gain doit croître avec le niveau de la victime (écart $ecart)."
                );
            }
            $precedent = $gain;
        }
    }

    public function testLaPerteEstToujoursNegativeOuNulle(): void
    {
        for ($ecart = -200; $ecart <= 200; ++$ecart) {
            $perte = PvpConfig::perteVaincu(50, 50 + $ecart);

            $this->assertLessThanOrEqual(0, $perte, "Perdre ne peut pas rapporter (écart $ecart).");
            $this->assertGreaterThanOrEqual(PvpConfig::HONNEUR_PERTE_MIN, $perte, "écart $ecart");
        }
    }

    /** Le cœur de l'intention : écraser un débutant COÛTE de l'honneur. */
    public function testEcraserBeaucoupPlusFaibleCoute(): void
    {
        $this->assertLessThan(
            0,
            PvpConfig::gainVainqueur(50, 10),
            "Tuer quarante niveaux en dessous de soi doit coûter, pas rapporter le maximum."
        );
        $this->assertGreaterThan(0, PvpConfig::gainVainqueur(10, 50), 'Battre plus fort rapporte.');
        $this->assertSame(PvpConfig::HONNEUR_BASE, PvpConfig::gainVainqueur(30, 30), 'À niveau égal : la base.');
    }

    public function testLExperienceSuitLaMemeLogiqueEtResteBornee(): void
    {
        for ($ecart = -200; $ecart <= 200; ++$ecart) {
            $xp = PvpConfig::experiencePour(50, 50 + $ecart);
            $this->assertGreaterThanOrEqual(PvpConfig::XP_MIN, $xp);
            $this->assertLessThanOrEqual(PvpConfig::XP_MAX, $xp);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Bornes et mutation                                                */
    /* ---------------------------------------------------------------- */

    public function testLHonneurEstBorne(): void
    {
        $user = $this->joueur(PvpConfig::HONNEUR_PLAFOND);
        $resultat = $this->service->ajuster($user, 500);

        $this->assertSame(PvpConfig::HONNEUR_PLAFOND, $resultat['honneur']);
        $this->assertSame(0, $resultat['delta'], "Un delta nul permet de ne pas annoncer un gain qui n'a pas eu lieu.");
    }

    public function testLePlancherEstRespecte(): void
    {
        $user = $this->joueur(PvpConfig::HONNEUR_PLANCHER);
        $this->assertSame(PvpConfig::HONNEUR_PLANCHER, $this->service->ajuster($user, -500)['honneur']);
    }

    /** `honneur` était nullable : `null + 20` donnait 20 sans prévenir. */
    public function testUnHonneurNullEstTraiteCommeZero(): void
    {
        $user = new User();
        $user->setHonneur(null);

        $this->assertSame(20, $this->service->ajuster($user, 20)['honneur']);
    }

    /* ---------------------------------------------------------------- */
    /* Anti-farm                                                         */
    /* ---------------------------------------------------------------- */

    public function testUnKillRecentAnnuleHonneur(): void
    {
        $vainqueur = $this->joueur(0);
        $vaincu = $this->joueur(0);

        $resultat = $this->service->appliquerVictoire($vainqueur, $vaincu, 20, 20, farm: true);

        $this->assertTrue($resultat['farm']);
        $this->assertSame(0, $resultat['vainqueur']['delta']);
        $this->assertSame(0, $resultat['vaincu']['delta']);
        $this->assertSame(0, $vainqueur->getHonneur(), "Rien ne doit avoir bougé.");
    }

    public function testUnePremiereVictoireApplique(): void
    {
        $vainqueur = $this->joueur(0);
        $vaincu = $this->joueur(0);

        $resultat = $this->service->appliquerVictoire($vainqueur, $vaincu, 20, 20, farm: false);

        $this->assertFalse($resultat['farm']);
        $this->assertSame(PvpConfig::HONNEUR_BASE, $resultat['vainqueur']['delta']);
        $this->assertLessThan(0, $resultat['vaincu']['delta']);
    }

    /**
     * `appliquerVictoire` ne doit PAS refaire le test lui-même : il serait alors joué APRÈS
     * l'écriture de `MORT_JOUEUR` et verrait le kill courant. C'est le bug constaté en jeu.
     */
    public function testAppliquerVictoireNInterrogePasLeJournal(): void
    {
        $this->evenementRepository->expects($this->never())->method('compterMortsInfligees');

        $this->service->appliquerVictoire($this->joueur(0), $this->joueur(0), 20, 20, farm: false);
    }

    public function testDejaTueRecemmentLitLaFenetreDeConfig(): void
    {
        $this->evenementRepository->expects($this->once())
            ->method('compterMortsInfligees')
            ->with(7, 9, PvpConfig::FENETRE_ANTI_FARM_HEURES)
            ->willReturn(1);

        $this->assertTrue($this->service->dejaTueRecemment($this->joueur(0, 7), $this->joueur(0, 9)));
    }

    /* ---------------------------------------------------------------- */

    public function testLesPaliersCouvrentToutLIntervalle(): void
    {
        for ($honneur = PvpConfig::HONNEUR_PLANCHER; $honneur <= PvpConfig::HONNEUR_PLAFOND; $honneur += 7) {
            $this->assertNotSame('', HonneurService::palier($honneur), "palier manquant à $honneur");
        }
    }

    private function joueur(int $honneur, int $id = 1): User
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id);
        $user->setHonneur($honneur);

        return $user;
    }
}
