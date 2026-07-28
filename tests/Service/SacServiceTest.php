<?php

namespace App\Tests\Service;

use App\Entity\Inventaire;
use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\ReservationRessource;
use App\Entity\User;
use App\Enum\TypeItem;
use App\Enum\TypeRessource;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\ObjetRepository;
use App\Repository\ReservationRessourceRepository;
use App\service\SacService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du point d'entrée unique des mutations items/or : piles (upsert/décrément/
 * suppression), or, et surtout les réservations — le disponible (possédé − réservé) est la
 * seule quantité utilisable par la vente, la consommation, l'équipement ou une dépense.
 */
class SacServiceTest extends TestCase
{
    private InventaireRepository|MockObject $inventaireRepository;
    private InventaireObjetRepository|MockObject $inventaireObjetRepository;
    private ObjetRepository|MockObject $objetRepository;
    private ReservationRessourceRepository|MockObject $reservationRessourceRepository;
    /* EntityManager concret : wrapInTransaction n'est qu'une annotation @method sur
       l'interface en ORM 2.x, donc non mockable dessus. */
    private EntityManager|MockObject $entityManager;
    private SacService $service;

    /** @var object[] entités passées à persist() */
    private array $persisted = [];
    /** @var object[] entités passées à remove() */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->inventaireRepository = $this->createMock(InventaireRepository::class);
        $this->inventaireObjetRepository = $this->createMock(InventaireObjetRepository::class);
        $this->objetRepository = $this->createMock(ObjetRepository::class);
        $this->reservationRessourceRepository = $this->createMock(ReservationRessourceRepository::class);

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        $this->service = new SacService(
            $this->entityManager,
            $this->inventaireRepository,
            $this->createMock(InventaireEquipementRepository::class),
            $this->createMock(InventaireConsommableRepository::class),
            $this->inventaireObjetRepository,
            $this->createMock(EquipementRepository::class),
            $this->createMock(ConsommableRepository::class),
            $this->objetRepository,
            $this->reservationRessourceRepository
        );
    }

    /* ---------------------------------------------------------------- */
    /* Piles                                                             */
    /* ---------------------------------------------------------------- */

    public function testAjouterEmpileSurLaLigneExistante(): void
    {
        $ligne = $this->makeLigne(3);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);

        $this->service->ajouterItem($this->makeUser(), TypeItem::OBJET, 7, 2);

        $this->assertSame(5, $ligne->getQuantity());
        $this->assertEmpty($this->persisted, "Aucune nouvelle ligne quand la pile existe.");
    }

    public function testAjouterCreeLaLigneManquante(): void
    {
        $objet = new Objet();
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn(null);
        $this->objetRepository->method('find')->willReturn($objet);

        $this->service->ajouterItem($this->makeUser(), TypeItem::OBJET, 7, 4);

        $this->assertCount(1, $this->persisted);
        $ligne = $this->persisted[0];
        $this->assertInstanceOf(InventaireObjet::class, $ligne);
        $this->assertSame(4, $ligne->getQuantity());
        $this->assertSame($objet, $ligne->getObjet());
    }

    public function testAjouterRefuseUnItemInconnu(): void
    {
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->objetRepository->method('find')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cet objet n'existe pas.");
        $this->service->ajouterItem($this->makeUser(), TypeItem::OBJET, 999, 1);
    }

    public function testRetirerDecrementeLaPile(): void
    {
        $ligne = $this->makeLigne(5);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);

        $this->service->retirerItem($this->makeUser(), TypeItem::OBJET, 7, 2);

        $this->assertSame(3, $ligne->getQuantity());
        $this->assertNotContains($ligne, $this->removed);
    }

    public function testRetirerLeDernierExemplaireSupprimeLaLigne(): void
    {
        $ligne = $this->makeLigne(2);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);

        $this->service->retirerItem($this->makeUser(), TypeItem::OBJET, 7, 2);

        $this->assertContains($ligne, $this->removed);
    }

    public function testRetirerRefuseAuDelaDuPossede(): void
    {
        $ligne = $this->makeLigne(2);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous n'en possédez que 2.");
        $this->service->retirerItem($this->makeUser(), TypeItem::OBJET, 7, 3);
    }

    public function testRetirerRespecteLesReservations(): void
    {
        // 5 possédés, 4 réservés par un échange : une seule unité reste utilisable.
        $ligne = $this->makeLigne(5);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(4);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Seuls 1 exemplaires sont disponibles, le reste est réservé.");
        $this->service->retirerItem($this->makeUser(), TypeItem::OBJET, 7, 2);
    }

    /* ---------------------------------------------------------------- */
    /* Or                                                                */
    /* ---------------------------------------------------------------- */

    public function testDebiterOrRespecteLesReservations(): void
    {
        $user = $this->makeUser(100);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(80);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous n'avez pas assez d'or.");
        $this->service->debiterOr($user, 50);
    }

    public function testDebiterOrDansLaLimiteDuDisponible(): void
    {
        $user = $this->makeUser(100);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(80);

        $this->service->debiterOr($user, 20);

        $this->assertSame(80, $user->getMoney());
    }

    public function testMontantsNegatifsRefuses(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->debiterOr($this->makeUser(10), -5);
    }

    /* ---------------------------------------------------------------- */
    /* Réservations                                                      */
    /* ---------------------------------------------------------------- */

    public function testReserverRefuseAuDelaDuDisponible(): void
    {
        $ligne = $this->makeLigne(3);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        // 2 unités déjà réservées par une AUTRE origine.
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(2);
        $this->reservationRessourceRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous ne possédez pas cette quantité.");
        $this->service->reserver($this->makeUser(), TypeRessource::OBJET, 7, 2, 'echange', 42);
    }

    public function testReserverCreeLaReservation(): void
    {
        $ligne = $this->makeLigne(5);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(0);
        $this->reservationRessourceRepository->method('findOneBy')->willReturn(null);

        $this->service->reserver($this->makeUser(), TypeRessource::OBJET, 7, 3, 'echange', 42);

        $this->assertCount(1, $this->persisted);
        $reservation = $this->persisted[0];
        $this->assertInstanceOf(ReservationRessource::class, $reservation);
        $this->assertSame(3, $reservation->getQuantite());
        $this->assertSame('echange', $reservation->getOrigine());
        $this->assertSame(42, $reservation->getOrigineId());
    }

    public function testReserverAjusteEnValeurAbsolueLaReservationExistante(): void
    {
        // 5 possédés, 3 déjà réservés par CETTE origine : redemander 5 doit passer
        // (le disponible hors cette réservation est 5 − (3 − 3) = 5).
        $ligne = $this->makeLigne(5);
        $reservation = (new ReservationRessource())->setQuantite(3);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(3);
        $this->reservationRessourceRepository->method('findOneBy')->willReturn($reservation);

        $this->service->reserver($this->makeUser(), TypeRessource::OBJET, 7, 5, 'echange', 42);

        $this->assertSame(5, $reservation->getQuantite());
        $this->assertEmpty($this->persisted, "Pas de doublon : la réservation existante est ajustée.");
    }

    public function testReserverZeroSupprimeLaReservation(): void
    {
        $ligne = $this->makeLigne(5);
        $reservation = (new ReservationRessource())->setQuantite(3);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(3);
        $this->reservationRessourceRepository->method('findOneBy')->willReturn($reservation);

        $this->service->reserver($this->makeUser(), TypeRessource::OBJET, 7, 0, 'echange', 42);

        $this->assertContains($reservation, $this->removed);
    }

    public function testReserverDeLOrPlafonneAuSolde(): void
    {
        $user = $this->makeUser(100);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(0);
        $this->reservationRessourceRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous n'avez pas assez d'or.");
        $this->service->reserver($user, TypeRessource::OR, 0, 150, 'echange', 42);
    }

    public function testLibererReservationsEstIdempotent(): void
    {
        $reservation = new ReservationRessource();
        $this->reservationRessourceRepository->method('findByOrigine')
            ->willReturnOnConsecutiveCalls([$reservation], []);

        $this->service->libererReservations('echange', 42);
        $this->service->libererReservations('echange', 42);

        $this->assertSame([$reservation], $this->removed, "Un second appel ne doit rien libérer ni échouer.");
    }

    /* ---------------------------------------------------------------- */

    private function makeUser(int $money = 0): User
    {
        $user = new User();
        $this->setId($user, 1);
        $user->setMoney($money);

        return $user;
    }

    private function makeInventaire(): Inventaire
    {
        $inventaire = new Inventaire();
        $this->setId($inventaire, 1);

        return $inventaire;
    }

    private function makeLigne(int $quantity): InventaireObjet
    {
        $ligne = new InventaireObjet();
        $ligne->setInventaire($this->makeInventaire());
        $ligne->setObjet(new Objet());
        $ligne->setQuantity($quantity);

        return $ligne;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
