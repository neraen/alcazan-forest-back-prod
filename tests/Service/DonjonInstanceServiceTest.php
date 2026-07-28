<?php

namespace App\Tests\Service;

use App\Entity\Donjon;
use App\Repository\DonjonInstanceRepository;
use App\Repository\DonjonRepository;
use App\Repository\DonjonSalleRepository;
use App\Repository\DonjonVerrouRepository;
use App\Repository\NiveauJoueurRepository;
use App\service\DonjonInstanceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le découpage du « jour de donjon » : c'est lui qui matérialise le « une fois par jour »,
 * et c'est la seule règle du service qu'on peut isoler sans base.
 *
 * `heure_reset` s'entend en HEURE DE PARIS — PHP tourne en UTC, donc tous les instants de
 * ce fichier sont construits explicitement dans un fuseau, jamais « nus ».
 */
class DonjonInstanceServiceTest extends TestCase
{
    private function makeService(): DonjonInstanceService
    {
        return new DonjonInstanceService(
            $this->createMock(DonjonRepository::class),
            $this->createMock(DonjonSalleRepository::class),
            $this->createMock(DonjonInstanceRepository::class),
            $this->createMock(DonjonVerrouRepository::class),
            $this->createMock(NiveauJoueurRepository::class),
            $this->createMock(\App\Repository\CarteCarreauRepository::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger()
        );
    }

    private function donjon(int $heureReset = 5): Donjon
    {
        return (new Donjon())->setHeureReset($heureReset);
    }

    /** Un instant exprimé en heure de Paris (ce que voit le joueur). */
    private function paris(string $quand): \DateTimeImmutable
    {
        return new \DateTimeImmutable($quand, new \DateTimeZone('Europe/Paris'));
    }

    /** Le même instant vu du serveur (UTC) : c'est ce que reçoit le service en production. */
    private function utc(string $quand): \DateTimeImmutable
    {
        return new \DateTimeImmutable($quand, new \DateTimeZone('UTC'));
    }

    public function testUneSessionApresLeResetCompteePourLeJourCourant(): void
    {
        $jour = $this->makeService()->jourDeDonjon($this->donjon(), $this->paris('2026-07-25 14:00:00'));

        $this->assertSame('2026-07-25', $jour->format('Y-m-d'));
    }

    /** Le cas qui justifie le décalage : 2 h du matin appartient encore à la veille. */
    public function testUneSessionNocturneCompteePourLaVeille(): void
    {
        $jour = $this->makeService()->jourDeDonjon($this->donjon(), $this->paris('2026-07-25 02:00:00'));

        $this->assertSame('2026-07-24', $jour->format('Y-m-d'));
    }

    public function testLeJourBasculePileALHeureDeReset(): void
    {
        $service = $this->makeService();

        $this->assertSame(
            '2026-07-24',
            $service->jourDeDonjon($this->donjon(), $this->paris('2026-07-25 04:59:59'))->format('Y-m-d')
        );
        $this->assertSame(
            '2026-07-25',
            $service->jourDeDonjon($this->donjon(), $this->paris('2026-07-25 05:00:00'))->format('Y-m-d')
        );
    }

    public function testDeuxSessionsDuMemeJourDeDonjonPartagentLeMemeVerrou(): void
    {
        $service = $this->makeService();
        $donjon = $this->donjon();

        // 23 h le 25, puis 3 h le 26 : à cheval sur minuit, mais un seul jour de donjon.
        $this->assertEquals(
            $service->jourDeDonjon($donjon, $this->paris('2026-07-25 23:00:00')),
            $service->jourDeDonjon($donjon, $this->paris('2026-07-26 03:00:00'))
        );
    }

    public function testLHeureDeResetEstConfigurableParDonjon(): void
    {
        $service = $this->makeService();

        // Avec un reset à minuit, 2 h du matin appartient bien au jour courant.
        $this->assertSame(
            '2026-07-25',
            $service->jourDeDonjon($this->donjon(0), $this->paris('2026-07-25 02:00:00'))->format('Y-m-d')
        );
    }

    public function testProchainResetTombeALHeureConfigureeLeLendemain(): void
    {
        $reset = $this->makeService()->prochainReset($this->donjon(), $this->paris('2026-07-25 14:00:00'));

        $this->assertSame('2026-07-26 05:00:00', $reset->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Paris', $reset->getTimezone()->getName());
    }

    public function testProchainResetDUneSessionNocturneEstLeMatinMeme(): void
    {
        $reset = $this->makeService()->prochainReset($this->donjon(), $this->paris('2026-07-25 02:00:00'));

        $this->assertSame('2026-07-25 05:00:00', $reset->format('Y-m-d H:i:s'));
    }

    /* ---------------------------------------------------------------- */
    /* Fuseau : l'heure de reset est celle des JOUEURS, pas du serveur    */
    /* ---------------------------------------------------------------- */

    /**
     * Le cas qui a motivé le correctif : le serveur tourne en UTC. En été, 04:00 UTC =
     * 06:00 à Paris, donc le reset de 5 h est DÉJÀ passé — sans conversion, le service
     * aurait rattaché cet instant à la veille.
     */
    public function testLHeureDeResetSInterpreteAParisPasEnUtc(): void
    {
        $service = $this->makeService();

        $this->assertSame(
            '2026-07-25',
            $service->jourDeDonjon($this->donjon(), $this->utc('2026-07-25 04:00:00'))->format('Y-m-d'),
            '04:00 UTC = 06:00 à Paris : le reset de 5 h est passé'
        );
        $this->assertSame(
            '2026-07-24',
            $service->jourDeDonjon($this->donjon(), $this->utc('2026-07-25 02:00:00'))->format('Y-m-d'),
            '02:00 UTC = 04:00 à Paris : encore la veille'
        );
    }

    public function testLeResetAnnonceEstDonneEnHeureDeParis(): void
    {
        $reset = $this->makeService()->prochainReset($this->donjon(), $this->utc('2026-07-25 12:00:00'));

        // Le joueur doit lire « 05:00 », pas « 07:00 » : c'est ce que l'admin a saisi.
        $this->assertSame('05:00', $reset->format('H:i'));
    }

    /**
     * Changement d'heure : la frontière reste à 5 h murales. Une soustraction de 5 heures
     * sur un timestamp l'aurait décalée d'une heure supplémentaire ce jour-là.
     */
    public function testLaFrontiereTientAuChangementDHeure(): void
    {
        $service = $this->makeService();

        // Passage à l'heure d'été 2026 : 2 h → 3 h dans la nuit du 28 au 29 mars.
        $this->assertSame(
            '2026-03-28',
            $service->jourDeDonjon($this->donjon(), $this->paris('2026-03-29 04:30:00'))->format('Y-m-d')
        );
        $this->assertSame(
            '2026-03-29',
            $service->jourDeDonjon($this->donjon(), $this->paris('2026-03-29 05:30:00'))->format('Y-m-d')
        );
        $this->assertSame(
            '2026-03-29 05:00',
            $service->prochainReset($this->donjon(), $this->paris('2026-03-28 20:00:00'))->format('Y-m-d H:i')
        );
    }
}
