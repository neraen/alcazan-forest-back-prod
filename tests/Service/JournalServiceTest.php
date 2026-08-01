<?php

namespace App\Tests\Service;

use App\Enum\TypeCible;
use App\Enum\TypeEvenement;
use App\Enum\TypeItem;
use App\Repository\EvenementJeuRepository;
use App\service\JournalService;
use App\service\SacService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le journal a deux invariants, et ce sont eux que ce fichier éprouve :
 *
 *  1. il ne doit JAMAIS faire échouer une action de jeu ;
 *  2. il ne doit JAMAIS mentir sur une action qui n'a pas eu lieu.
 *
 * Le second se vérifie par le rollback : le service écrit en SQL natif, donc hors unité de
 * travail, mais sur la MÊME connexion — il participe à la transaction de l'appelant, et une
 * annulation doit emporter la ligne avec elle. C'est le test qui distingue cette conception
 * d'une écriture différée après commit, qui journaliserait des faits jamais advenus.
 */
class JournalServiceTest extends KernelTestCase
{
    private JournalService $service;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->service = $container->get(JournalService::class);
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
    }

    /* ---------------------------------------------------------------- */
    /* Écriture nominale                                                 */
    /* ---------------------------------------------------------------- */

    public function testUnEvenementEstEcritAvecSonContexte(): void
    {
        $marqueur = uniqid('journal', true);

        $this->service->consigner(
            type: TypeEvenement::MONSTRE_TUE,
            cibleType: TypeCible::MONSTRE,
            cibleId: 4242,
            quantite: 3,
            montantOr: 17,
            contexte: ['marqueur' => $marqueur, 'butin' => ['Dent de loup']],
        );

        $ligne = $this->ligneAvecMarqueur($marqueur);

        $this->assertNotNull($ligne, "L'événement doit être écrit immédiatement.");
        $this->assertSame('monstre_tue', $ligne['type']);
        $this->assertSame('monstre', $ligne['cible_type']);
        $this->assertSame(4242, (int) $ligne['cible_id']);
        $this->assertSame(3, (int) $ligne['quantite']);
        $this->assertSame(17, (int) $ligne['montant_or']);

        $contexte = json_decode($ligne['contexte'], true);
        $this->assertSame(['Dent de loup'], $contexte['butin'], 'Le contexte JSON doit être relu à l\'identique.');

        $this->nettoyer($marqueur);
    }

    /** Un contexte vide ne doit pas écrire `"[]"` mais NULL : une absence est une absence. */
    public function testUnContexteVideEstEcritNull(): void
    {
        $this->connection->executeStatement("DELETE FROM evenement_jeu WHERE type = 'connexion' AND cible_id = 777");

        $this->service->consigner(type: TypeEvenement::CONNEXION, cibleId: 777);

        $contexte = $this->connection->fetchOne(
            "SELECT contexte FROM evenement_jeu WHERE type = 'connexion' AND cible_id = 777"
        );
        $this->assertNull($contexte);

        $this->connection->executeStatement("DELETE FROM evenement_jeu WHERE type = 'connexion' AND cible_id = 777");
    }

    /* ---------------------------------------------------------------- */
    /* Invariant n°2 : ne jamais mentir                                   */
    /* ---------------------------------------------------------------- */

    public function testUnRollbackDeLAppelantEfaceLEvenement(): void
    {
        $marqueur = uniqid('journal', true);

        $this->connection->beginTransaction();
        $this->service->consigner(
            type: TypeEvenement::ECHANGE_CONCLU,
            contexte: ['marqueur' => $marqueur],
        );
        // Visible DANS la transaction : la ligne est bien écrite, pas mise de côté.
        $this->assertNotNull($this->ligneAvecMarqueur($marqueur), "L'écriture est immédiate.");
        $this->connection->rollBack();

        $this->assertNull(
            $this->ligneAvecMarqueur($marqueur),
            "Une action annulée ne doit laisser AUCUNE trace : un journal qui garde un échange "
            . "annulé enverrait l'enquête sur une fausse piste."
        );
    }

    /* ---------------------------------------------------------------- */
    /* Invariant n°1 : ne jamais faire échouer une action                 */
    /* ---------------------------------------------------------------- */

    public function testUneEcritureEnEchecNeRemontePasDException(): void
    {
        $repository = $this->createMock(EvenementJeuRepository::class);
        $repository->method('inserer')->willThrowException(new \RuntimeException('colonne trop courte'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service = new JournalService($repository, $this->createMock(SacService::class), $logger);

        $service->consigner(type: TypeEvenement::MONSTRE_TUE);

        $this->addToAssertionCount(1); // aucune exception n'a traversé : c'est tout l'enjeu
    }

    public function testUnLotEnEchecNeRemontePasDException(): void
    {
        $repository = $this->createMock(EvenementJeuRepository::class);
        $repository->method('insererPlusieurs')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service = new JournalService($repository, $this->createMock(SacService::class), $logger);

        $service->consignerPlusieurs([['type' => TypeEvenement::HDV_EXPIRATION]]);

        $this->addToAssertionCount(1);
    }

    /* ---------------------------------------------------------------- */
    /* Lot                                                               */
    /* ---------------------------------------------------------------- */

    public function testConsignerPlusieursNeFaitQuUnSeulInsert(): void
    {
        $repository = $this->createMock(EvenementJeuRepository::class);
        $repository->expects($this->once())
            ->method('insererPlusieurs')
            ->with($this->countOf(3));

        $service = new JournalService($repository, $this->createMock(SacService::class), $this->createMock(LoggerInterface::class));

        $service->consignerPlusieurs([
            ['type' => TypeEvenement::HDV_EXPIRATION, 'quantite' => 1],
            ['type' => TypeEvenement::HDV_EXPIRATION, 'quantite' => 2],
            ['type' => TypeEvenement::HDV_EXPIRATION, 'quantite' => 3],
        ]);
    }

    public function testUnLotVideNEcritRien(): void
    {
        $repository = $this->createMock(EvenementJeuRepository::class);
        $repository->expects($this->never())->method('insererPlusieurs');

        $service = new JournalService($repository, $this->createMock(SacService::class), $this->createMock(LoggerInterface::class));

        $service->consignerPlusieurs([]);
    }

    /* ---------------------------------------------------------------- */
    /* Figeage des noms d'items                                          */
    /* ---------------------------------------------------------------- */

    public function testLeNomDUnItemEstFigeAuMomentDuFait(): void
    {
        $sacService = $this->createMock(SacService::class);
        $sacService->method('decrireItem')->willReturn(['nom' => 'Épée courte']);

        $service = new JournalService(
            $this->createMock(EvenementJeuRepository::class),
            $sacService,
            $this->createMock(LoggerInterface::class)
        );

        $items = $service->figerItems([['type' => TypeItem::EQUIPEMENT, 'id' => 12, 'quantite' => 2]]);

        $this->assertSame(
            [['type' => 'equipement', 'id' => 12, 'quantite' => 2, 'nom' => 'Épée courte']],
            $items,
            "Le nom doit être gravé dans l'événement : `item_id` n'a pas de clé étrangère, "
            . "aucune requête ne pourra le retrouver plus tard."
        );
    }

    /** Un item disparu du contenu ne doit pas empêcher de journaliser le fait lui-même. */
    public function testUnItemIntrouvableNEmpechePasDeJournaliser(): void
    {
        $sacService = $this->createMock(SacService::class);
        $sacService->method('decrireItem')->willThrowException(new \DomainException("Cet objet n'existe pas."));

        $service = new JournalService(
            $this->createMock(EvenementJeuRepository::class),
            $sacService,
            $this->createMock(LoggerInterface::class)
        );

        $items = $service->figerItems([['type' => TypeItem::OBJET, 'id' => 99999]]);

        $this->assertSame('Objet inconnu (#99999)', $items[0]['nom']);
        $this->assertSame(1, $items[0]['quantite'], 'La quantité par défaut est 1.');
    }

    /* ---------------------------------------------------------------- */

    private function ligneAvecMarqueur(string $marqueur): ?array
    {
        $ligne = $this->connection->fetchAssociative(
            "SELECT * FROM evenement_jeu WHERE JSON_EXTRACT(contexte, '$.marqueur') = :marqueur",
            ['marqueur' => $marqueur]
        );

        return $ligne === false ? null : $ligne;
    }

    private function nettoyer(string $marqueur): void
    {
        $this->connection->executeStatement(
            "DELETE FROM evenement_jeu WHERE JSON_EXTRACT(contexte, '$.marqueur') = :marqueur",
            ['marqueur' => $marqueur]
        );
    }
}
