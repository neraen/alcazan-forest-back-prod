<?php

namespace App\Tests\Service;

use App\Entity\Consommable;
use App\Entity\Equipement;
use App\Entity\Inventaire;
use App\Entity\InventaireConsommable;
use App\Entity\InventaireEquipement;
use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\User;
use App\Enum\TypeItem;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\ObjetRepository;
use App\Repository\ReservationRessourceRepository;
use App\service\CumulJoueurService;
use App\service\JournalService;
use App\service\SacService;
use App\service\VenteService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la vente au marchand : prix pris sur l'item (0 si non renseigné),
 * décrément de pile et crédit de l'or, le tout dans une transaction. Le service s'appuie
 * sur un VRAI SacService (repositories mockés) : les règles de pile et de réservation
 * sont donc exercées, pas simulées.
 */
class VenteServiceTest extends TestCase
{
    private InventaireRepository|MockObject $inventaireRepository;
    private InventaireEquipementRepository|MockObject $inventaireEquipementRepository;
    private InventaireConsommableRepository|MockObject $inventaireConsommableRepository;
    private InventaireObjetRepository|MockObject $inventaireObjetRepository;
    private EquipementRepository|MockObject $equipementRepository;
    private ConsommableRepository|MockObject $consommableRepository;
    private ObjetRepository|MockObject $objetRepository;
    private ReservationRessourceRepository|MockObject $reservationRessourceRepository;
    /* EntityManager concret : wrapInTransaction n'est qu'une annotation @method sur
       l'interface en ORM 2.x, donc non mockable dessus. */
    private EntityManager|MockObject $entityManager;
    private VenteService $service;

    /** @var object[] entités passées à remove() */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->inventaireRepository = $this->createMock(InventaireRepository::class);
        $this->inventaireEquipementRepository = $this->createMock(InventaireEquipementRepository::class);
        $this->inventaireConsommableRepository = $this->createMock(InventaireConsommableRepository::class);
        $this->inventaireObjetRepository = $this->createMock(InventaireObjetRepository::class);
        $this->equipementRepository = $this->createMock(EquipementRepository::class);
        $this->consommableRepository = $this->createMock(ConsommableRepository::class);
        $this->objetRepository = $this->createMock(ObjetRepository::class);
        $this->reservationRessourceRepository = $this->createMock(ReservationRessourceRepository::class);

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        $sacService = new SacService(
            $this->entityManager,
            $this->inventaireRepository,
            $this->inventaireEquipementRepository,
            $this->inventaireConsommableRepository,
            $this->inventaireObjetRepository,
            $this->equipementRepository,
            $this->consommableRepository,
            $this->objetRepository,
            $this->reservationRessourceRepository
        );

        $this->service = new VenteService(
            $this->entityManager,
            $sacService,
            $this->createMock(JournalService::class),
            $this->createMock(CumulJoueurService::class)
        );
    }

    public function testVenteDUnEquipementCrediteSonPrixDeRevente(): void
    {
        $user = $this->makeUser(500);
        $inventaire = $this->makeInventaire();
        $equipement = new Equipement();
        $equipement->setNom('Anneau de fer');
        $equipement->setPrixRevente(125);
        $ligne = $this->makeLigne(new InventaireEquipement(), $inventaire, $equipement, 2);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')->willReturn($ligne);
        $this->equipementRepository->method('find')->willReturn($equipement);

        $vente = $this->service->sell($user, TypeItem::EQUIPEMENT, 8);

        $this->assertSame(125, $vente['prix']);
        $this->assertSame('Anneau de fer', $vente['nom']);
        $this->assertSame(625, $vente['money']);
        $this->assertSame(625, $user->getMoney());
        $this->assertSame(1, $ligne->getQuantity(), 'La pile doit être décrémentée.');
        $this->assertNotContains($ligne, $this->removed);
    }

    public function testVenteDuDernierExemplaireSupprimeLaLigne(): void
    {
        $user = $this->makeUser(0);
        $inventaire = $this->makeInventaire();
        $consommable = new Consommable();
        $consommable->setNom('Potion de vie mineure');
        $consommable->setPrixRevente(14);
        $ligne = $this->makeLigne(new InventaireConsommable(), $inventaire, $consommable, 1);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireConsommableRepository->method('findOneBy')->willReturn($ligne);
        $this->consommableRepository->method('find')->willReturn($consommable);

        $vente = $this->service->sell($user, TypeItem::CONSOMMABLE, 1);

        $this->assertSame(14, $vente['money']);
        $this->assertContains($ligne, $this->removed);
    }

    public function testObjetSansPrixDeVenteSeVendZero(): void
    {
        $user = $this->makeUser(300);
        $inventaire = $this->makeInventaire();
        $objet = new Objet();
        $objet->setName('Chapeau de champignon bleu');
        $objet->setPrixVente(null);
        $ligne = $this->makeLigne(new InventaireObjet(), $inventaire, $objet, 3);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->objetRepository->method('find')->willReturn($objet);

        $vente = $this->service->sell($user, TypeItem::OBJET, 4);

        $this->assertSame(0, $vente['prix']);
        $this->assertSame(300, $user->getMoney(), "L'or ne bouge pas, mais l'objet part quand même.");
        $this->assertSame(2, $ligne->getQuantity());
    }

    /* ---------------------------------------------------------------- */
    /* Vente par lot                                                     */
    /* ---------------------------------------------------------------- */

    public function testVenteDePlusieursExemplairesMultiplieLePrix(): void
    {
        $user = $this->makeUser(100);
        $inventaire = $this->makeInventaire();
        $objet = new Objet();
        $objet->setName('Coquille bleu');
        $objet->setPrixVente(18);
        $ligne = $this->makeLigne(new InventaireObjet(), $inventaire, $objet, 11);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->objetRepository->method('find')->willReturn($objet);

        $vente = $this->service->sell($user, TypeItem::OBJET, 2, 4);

        $this->assertSame(72, $vente['prix']);
        $this->assertSame(18, $vente['prixUnitaire']);
        $this->assertSame(4, $vente['quantite']);
        $this->assertSame(172, $user->getMoney());
        $this->assertSame(7, $ligne->getQuantity());
        $this->assertNotContains($ligne, $this->removed);
    }

    public function testVendreToutLeStockSupprimeLaLigne(): void
    {
        $user = $this->makeUser(0);
        $inventaire = $this->makeInventaire();
        $objet = new Objet();
        $objet->setName('Coquille bleu');
        $objet->setPrixVente(18);
        $ligne = $this->makeLigne(new InventaireObjet(), $inventaire, $objet, 3);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->objetRepository->method('find')->willReturn($objet);

        $vente = $this->service->sell($user, TypeItem::OBJET, 2, 3);

        $this->assertSame(54, $vente['money']);
        $this->assertContains($ligne, $this->removed);
    }

    public function testVenteRefuseUneQuantiteSuperieureAuStock(): void
    {
        $user = $this->makeUser(0);
        $inventaire = $this->makeInventaire();
        $objet = new Objet();
        $objet->setName('Coquille bleu');
        $objet->setPrixVente(18);
        $ligne = $this->makeLigne(new InventaireObjet(), $inventaire, $objet, 2);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous n'en possédez que 2.");
        $this->service->sell($user, TypeItem::OBJET, 2, 5);
    }

    public function testVenteRefuseUneQuantiteNulleOuNegative(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Quantité invalide.");
        $this->service->sell($this->makeUser(0), TypeItem::OBJET, 2, 0);
    }

    public function testVenteRefuseUnItemAbsentDuSac(): void
    {
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireEquipementRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cet objet n'est pas dans votre inventaire.");
        $this->service->sell($this->makeUser(0), TypeItem::EQUIPEMENT, 8);
    }

    public function testVenteRefuseSansInventaire(): void
    {
        $this->inventaireRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->service->sell($this->makeUser(0), TypeItem::OBJET, 1);
    }

    /* ---------------------------------------------------------------- */

    private function makeUser(int $money): User
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

    /**
     * @template T of InventaireEquipement|InventaireConsommable|InventaireObjet
     * @param T $ligne
     * @return T
     */
    private function makeLigne(object $ligne, Inventaire $inventaire, object $item, int $quantity): object
    {
        $ligne->setInventaire($inventaire);
        $ligne->setQuantity($quantity);

        match (true) {
            $ligne instanceof InventaireEquipement => $ligne->setEquipement($item),
            $ligne instanceof InventaireConsommable => $ligne->setConsommable($item),
            $ligne instanceof InventaireObjet => $ligne->setObjet($item),
        };

        return $ligne;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
