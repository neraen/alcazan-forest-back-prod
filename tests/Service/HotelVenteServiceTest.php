<?php

namespace App\Tests\Service;

use App\Config\HotelVenteConfig;
use App\Entity\HotelVente;
use App\Entity\Inventaire;
use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\User;
use App\Enum\StatutHotelVente;
use App\Enum\TypeItem;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\HotelVenteRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\ObjetRepository;
use App\Repository\ReservationRessourceRepository;
use App\service\CumulJoueurService;
use App\service\HotelVenteNormalizer;
use App\service\HotelVenteService;
use App\service\JournalService;
use App\service\SacService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests unitaires de l'hôtel des ventes : règles de dépôt et de retrait, avec un VRAI
 * SacService (repositories mockés) — les frais et le séquestre sont donc réellement exercés
 * sur des piles d'inventaire, pas simulés.
 *
 * Ce qui relève de la concurrence (verrous, double achat, 409) n'est pas testable ici : le
 * verrou pessimiste n'existe que contre une vraie base. C'est le rôle de
 * HotelVenteApiFunctionalTest.
 */
class HotelVenteServiceTest extends TestCase
{
    private InventaireRepository|MockObject $inventaireRepository;
    private InventaireObjetRepository|MockObject $inventaireObjetRepository;
    private ObjetRepository|MockObject $objetRepository;
    private ReservationRessourceRepository|MockObject $reservationRessourceRepository;
    private HotelVenteRepository|MockObject $hotelVenteRepository;
    /* EntityManager concret : wrapInTransaction n'est qu'une annotation @method sur
       l'interface en ORM 2.x, donc non mockable dessus. */
    private EntityManager|MockObject $entityManager;
    private HotelVenteService $service;

    /** @var object[] entités passées à persist() */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->inventaireRepository = $this->createMock(InventaireRepository::class);
        $this->inventaireObjetRepository = $this->createMock(InventaireObjetRepository::class);
        $this->objetRepository = $this->createMock(ObjetRepository::class);
        $this->reservationRessourceRepository = $this->createMock(ReservationRessourceRepository::class);
        $this->hotelVenteRepository = $this->createMock(HotelVenteRepository::class);

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $sacService = new SacService(
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

        $this->service = new HotelVenteService(
            $this->entityManager,
            $this->hotelVenteRepository,
            $sacService,
            new HotelVenteNormalizer($sacService),
            $this->createMock(JournalService::class),
            $this->createMock(CumulJoueurService::class),
            new NullLogger()
        );
    }

    /* ---------------------------------------------------------------- */
    /* Frais de dépôt                                                    */
    /* ---------------------------------------------------------------- */

    public function testLesFraisSontUnPourcentageArrondiAuSuperieur(): void
    {
        // 5 % de 1000 = 50 ; 5 % de 101 = 5,05 → 6, l'hôtel ne rend pas la monnaie.
        $this->assertSame(50, HotelVenteConfig::fraisDepot(1000));
        $this->assertSame(6, HotelVenteConfig::fraisDepot(101));
    }

    public function testLesFraisNeDescendentJamaisSousLePlancher(): void
    {
        $this->assertSame(
            HotelVenteConfig::FRAIS_MINIMUM,
            HotelVenteConfig::fraisDepot(1),
            "Une annonce à 1 po ne doit jamais être gratuite."
        );
    }

    /* ---------------------------------------------------------------- */
    /* Dépôt                                                             */
    /* ---------------------------------------------------------------- */

    public function testLeDepotPreleveLesFraisEtSortLObjetDuSac(): void
    {
        $user = $this->makeUser(500);
        $ligne = $this->donnerObjets($user, 'Peau de loup', 5);
        $this->hotelVenteRepository->method('compterActivesDe')->willReturn(0);

        $resultat = $this->service->mettreEnVente($user, TypeItem::OBJET, 7, 3, 200);

        $this->assertSame(10, $resultat['annonce']['fraisDepot'], '5 % de 200.');
        $this->assertSame(490, $user->getMoney(), "Les frais sont prélevés au dépôt.");
        $this->assertSame(2, $ligne->getQuantity(), "Le lot a quitté le sac : séquestre, pas réservation.");
        $this->assertSame(200, $resultat['annonce']['prix']);
        $this->assertSame(3, $resultat['annonce']['quantite']);
        $this->assertSame('Peau de loup', $resultat['annonce']['item']['nom']);
        $this->assertSame('en_vente', $resultat['annonce']['statut']);

        $annonce = $this->derniereAnnoncePersistee();
        $this->assertNotNull($annonce, "L'annonce doit être persistée.");
        $this->assertGreaterThan(new \DateTimeImmutable(), $annonce->getExpiresAt());
    }

    public function testAucuneReservationNEstPoseeAuDepot(): void
    {
        $user = $this->makeUser(500);
        $this->donnerObjets($user, 'Peau de loup', 5);
        $this->hotelVenteRepository->method('compterActivesDe')->willReturn(0);

        // Le séquestre passe par retirerItem, jamais par reservation_ressource : rien ne doit
        // être écrit dans cette table, sans quoi l'objet compterait deux fois comme engagé.
        $this->service->mettreEnVente($user, TypeItem::OBJET, 7, 3, 200);

        foreach ($this->persisted as $entite) {
            $this->assertNotInstanceOf(
                \App\Entity\ReservationRessource::class,
                $entite,
                "Le dépôt ne doit poser AUCUNE réservation."
            );
        }
    }

    public function testLeDepotRefuseUnPrixHorsBornes(): void
    {
        $user = $this->makeUser(500);
        $this->donnerObjets($user, 'Peau de loup', 5);

        $this->expectException(\DomainException::class);
        $this->service->mettreEnVente($user, TypeItem::OBJET, 7, 1, HotelVenteConfig::PRIX_MAX + 1);
    }

    public function testLeDepotRefuseAuDelaDuPlafondDAnnonces(): void
    {
        $user = $this->makeUser(500);
        $ligne = $this->donnerObjets($user, 'Peau de loup', 5);
        $this->hotelVenteRepository->method('compterActivesDe')
            ->willReturn(HotelVenteConfig::ANNONCES_MAX_PAR_JOUEUR);

        try {
            $this->service->mettreEnVente($user, TypeItem::OBJET, 7, 1, 100);
            $this->fail("Le plafond d'annonces doit être opposé au vendeur.");
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('lots en vente', $exception->getMessage());
        }

        $this->assertSame(5, $ligne->getQuantity(), "Un dépôt refusé ne doit rien retirer du sac.");
        $this->assertSame(500, $user->getMoney(), "Un dépôt refusé ne doit rien prélever.");
    }

    public function testLeDepotRefuseUneQuantiteSuperieureAuStock(): void
    {
        $user = $this->makeUser(500);
        $this->donnerObjets($user, 'Peau de loup', 2);
        $this->hotelVenteRepository->method('compterActivesDe')->willReturn(0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous n'en possédez que 2.");
        $this->service->mettreEnVente($user, TypeItem::OBJET, 7, 5, 100);
    }

    public function testLeDepotRefuseSiLOrNeCouvrePasLesFrais(): void
    {
        $user = $this->makeUser(4);
        $this->donnerObjets($user, 'Peau de loup', 5);
        $this->hotelVenteRepository->method('compterActivesDe')->willReturn(0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous n'avez pas assez d'or.");
        $this->service->mettreEnVente($user, TypeItem::OBJET, 7, 1, 1000); // 50 po de frais
    }

    /* ---------------------------------------------------------------- */
    /* Retrait                                                           */
    /* ---------------------------------------------------------------- */

    public function testLeRetraitRendLObjetSansRembourserLesFrais(): void
    {
        $user = $this->makeUser(490);
        $ligne = $this->donnerObjets($user, 'Peau de loup', 2);
        $annonce = $this->makeAnnonce($user, 3, 200, 10);

        $this->hotelVenteRepository->method('find')->willReturn($annonce);

        $resultat = $this->service->retirer($user, 42);

        $this->assertSame(5, $ligne->getQuantity(), "Les 3 exemplaires séquestrés reviennent au sac.");
        $this->assertSame(490, $user->getMoney(), "Les frais de dépôt ne sont PAS remboursés.");
        $this->assertSame(StatutHotelVente::RETIREE, $annonce->getStatut());
        $this->assertStringContainsString('ne sont pas remboursés', $resultat['message']);
    }

    public function testOnNePeutPasRetirerLeLotDUnAutre(): void
    {
        $vendeur = $this->makeUser(0, 1);
        $intrus = $this->makeUser(0, 2);
        $this->hotelVenteRepository->method('find')->willReturn($this->makeAnnonce($vendeur, 3, 200, 10));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Ce lot n'est pas le vôtre.");
        $this->service->retirer($intrus, 42);
    }

    public function testOnNePeutPasRetirerDeuxFoisLeMemeLot(): void
    {
        $user = $this->makeUser(0);
        $this->donnerObjets($user, 'Peau de loup', 0);
        $annonce = $this->makeAnnonce($user, 3, 200, 10);
        $annonce->cloturer(StatutHotelVente::RETIREE);
        $this->hotelVenteRepository->method('find')->willReturn($annonce);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Ce lot n'est plus en vente.");
        $this->service->retirer($user, 42);
    }

    /* ---------------------------------------------------------------- */
    /* Achat                                                             */
    /* ---------------------------------------------------------------- */

    public function testOnNePeutPasAcheterSonPropreLot(): void
    {
        $user = $this->makeUser(10000);
        $this->donnerObjets($user, 'Peau de loup', 0);
        $this->hotelVenteRepository->method('find')->willReturn($this->makeAnnonce($user, 3, 200, 10));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Vous ne pouvez pas acheter votre propre lot.");
        $this->service->acheter($user, 42, 200);
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                           */
    /* ---------------------------------------------------------------- */

    private function makeUser(int $money, int $id = 1): User
    {
        $user = new User();
        $this->setId($user, $id);
        $user->setMoney($money);

        return $user;
    }

    /** Donne `$quantite` exemplaires de l'objet 7 au joueur ; renvoie la ligne de pile. */
    private function donnerObjets(User $user, string $nom, int $quantite): InventaireObjet
    {
        $inventaire = new Inventaire();
        $this->setId($inventaire, 1);

        $objet = new Objet();
        $this->setId($objet, 7);
        $objet->setName($nom);
        $objet->setPrixVente(12);

        $ligne = new InventaireObjet();
        $ligne->setInventaire($inventaire);
        $ligne->setObjet($objet);
        $ligne->setQuantity($quantite);

        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireObjetRepository->method('findOneBy')->willReturn($ligne);
        $this->objetRepository->method('find')->willReturn($objet);
        $this->reservationRessourceRepository->method('sommeReservee')->willReturn(0);

        return $ligne;
    }

    private function makeAnnonce(User $vendeur, int $quantite, int $prix, int $frais): HotelVente
    {
        $annonce = (new HotelVente())
            ->setVendeur($vendeur)
            ->setType(TypeItem::OBJET)
            ->setItemId(7)
            ->setQuantite($quantite)
            ->setPrix($prix)
            ->setFraisDepot($frais);
        $this->setId($annonce, 42);

        return $annonce;
    }

    private function derniereAnnoncePersistee(): ?HotelVente
    {
        foreach (array_reverse($this->persisted) as $entite) {
            if ($entite instanceof HotelVente) {
                return $entite;
            }
        }

        return null;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
