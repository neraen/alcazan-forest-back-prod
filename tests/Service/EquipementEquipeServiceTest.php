<?php

namespace App\Tests\Service;

use App\Entity\Caracteristique;
use App\Entity\Equipement;
use App\Entity\EquipementCaracteristique;
use App\Entity\Inventaire;
use App\Entity\InventaireEquipement;
use App\Entity\JoueurCaracteristiqueBonus;
use App\Entity\PositionEquipement;
use App\Entity\User;
use App\Entity\UserEquipement;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\ObjetRepository;
use App\Repository\ReservationRessourceRepository;
use App\Repository\UserEquipementRepository;
use App\service\EquipementEquipeService;
use App\service\SacService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du port d'équipement. Couvre la régression du 23/07/2026 : équiper un objet
 * sur une position déjà occupée remettait le NOUVEL objet dans le sac au lieu de l'ancien —
 * l'objet porté était dupliqué et l'ancien disparaissait.
 */
class EquipementEquipeServiceTest extends TestCase
{
    private EquipementRepository|MockObject $equipementRepository;
    private InventaireRepository|MockObject $inventaireRepository;
    private InventaireEquipementRepository|MockObject $inventaireEquipementRepository;
    private UserEquipementRepository|MockObject $userEquipementRepository;
    private JoueurCaracteristiqueBonusRepository|MockObject $joueurCaracteristiqueBonusRepository;
    /* EntityManager concret : wrapInTransaction n'est qu'une annotation @method sur
       l'interface en ORM 2.x, donc non mockable dessus. */
    private EntityManager|MockObject $entityManager;
    private EquipementEquipeService $service;

    /** @var object[] entités passées à persist() */
    private array $persisted = [];
    /** @var object[] entités passées à remove() */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->equipementRepository = $this->createMock(EquipementRepository::class);
        $this->inventaireRepository = $this->createMock(InventaireRepository::class);
        $this->inventaireEquipementRepository = $this->createMock(InventaireEquipementRepository::class);
        $this->userEquipementRepository = $this->createMock(UserEquipementRepository::class);
        $this->joueurCaracteristiqueBonusRepository = $this->createMock(JoueurCaracteristiqueBonusRepository::class);

        $this->entityManager = $this->createMock(EntityManager::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        // Vrai SacService (repositories mockés) : les règles de pile/réservation sont exercées.
        $sacService = new SacService(
            $this->entityManager,
            $this->inventaireRepository,
            $this->inventaireEquipementRepository,
            $this->createMock(InventaireConsommableRepository::class),
            $this->createMock(InventaireObjetRepository::class),
            $this->equipementRepository,
            $this->createMock(ConsommableRepository::class),
            $this->createMock(ObjetRepository::class),
            $this->createMock(ReservationRessourceRepository::class)
        );

        $this->service = new EquipementEquipeService(
            $this->entityManager,
            $this->equipementRepository,
            $this->userEquipementRepository,
            $this->joueurCaracteristiqueBonusRepository,
            $sacService
        );
    }

    /* ---------------------------------------------------------------- */
    /* Garde-fous                                                        */
    /* ---------------------------------------------------------------- */

    public function testWearRefuseUnEquipementInexistant(): void
    {
        $this->equipementRepository->method('find')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->service->wear($this->makeUser(1), 999);
    }

    public function testWearRefuseUnEquipementAbsentDuSac(): void
    {
        $equipement = $this->makeEquipement(5, 7);
        $this->equipementRepository->method('find')->willReturn($equipement);
        $this->inventaireRepository->method('findOneBy')->willReturn($this->makeInventaire());
        $this->inventaireEquipementRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cet équipement n'est pas dans votre inventaire.");
        $this->service->wear($this->makeUser(1), 5);
    }

    public function testWearRefuseUnEquipementDejaPorte(): void
    {
        $user = $this->makeUser(1);
        $equipement = $this->makeEquipement(5, 7);
        $inventaire = $this->makeInventaire();

        $this->equipementRepository->method('find')->willReturn($equipement);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')
            ->willReturn($this->makeLigneSac($inventaire, $equipement, 1));
        $this->userEquipementRepository->method('getEquipementEquipeByUserAndPosition')
            ->willReturn($this->makeUserEquipement($user, $equipement));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cet équipement est déjà porté.");
        $this->service->wear($user, 5);
    }

    public function testUnwearRefuseUnEquipementNonPorte(): void
    {
        $this->userEquipementRepository->method('findOneBy')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cet équipement n'est pas équipé.");
        $this->service->unwear($this->makeUser(1), 5);
    }

    /* ---------------------------------------------------------------- */
    /* Échange sur une position occupée (le bug du 23/07/2026)           */
    /* ---------------------------------------------------------------- */

    public function testWearRemetLAncienEquipementDansLeSacEtPasLeNouveau(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $ancien = $this->makeEquipement(5, 7);   // chapeau porté
        $nouveau = $this->makeEquipement(13, 7); // capuche à équiper (même position)
        $ligneNouveau = $this->makeLigneSac($inventaire, $nouveau, 1);

        $this->equipementRepository->method('find')
            ->willReturnCallback(fn (int $id) => [13 => $nouveau, 5 => $ancien][$id] ?? null);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->userEquipementRepository->method('getEquipementEquipeByUserAndPosition')
            ->willReturn($this->makeUserEquipement($user, $ancien));

        // SacService cherche les lignes par id d'équipement : le nouveau (13) est au sac,
        // l'ancien (5) n'y a pas de ligne — il doit en créer une.
        $this->inventaireEquipementRepository->method('findOneBy')
            ->willReturnCallback(fn (array $criteria) => [13 => $ligneNouveau][$criteria['equipement']] ?? null);

        $this->service->wear($user, 13);

        $ligneCreee = $this->onlyPersisted(InventaireEquipement::class);
        $this->assertSame(
            $ancien,
            $ligneCreee->getEquipement(),
            "C'est l'ANCIEN équipement qui doit retourner dans le sac, pas celui qu'on équipe."
        );
        $this->assertSame(1, $ligneCreee->getQuantity());

        // Le nouvel objet quitte le sac (dernier exemplaire => ligne supprimée)…
        $this->assertContains($ligneNouveau, $this->removed);
        // …et est bien celui qui finit porté.
        $this->assertSame($nouveau, $this->onlyPersisted(UserEquipement::class)->getEquipement());
    }

    public function testWearEmpileLAncienEquipementSurLaLigneExistante(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $ancien = $this->makeEquipement(5, 7);
        $nouveau = $this->makeEquipement(13, 7);
        $ligneNouveau = $this->makeLigneSac($inventaire, $nouveau, 1);
        $ligneAncien = $this->makeLigneSac($inventaire, $ancien, 1);

        $this->equipementRepository->method('find')
            ->willReturnCallback(fn (int $id) => [13 => $nouveau, 5 => $ancien][$id] ?? null);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->userEquipementRepository->method('getEquipementEquipeByUserAndPosition')
            ->willReturn($this->makeUserEquipement($user, $ancien));
        $this->inventaireEquipementRepository->method('findOneBy')
            ->willReturnCallback(fn (array $criteria) => [13 => $ligneNouveau, 5 => $ligneAncien][$criteria['equipement']] ?? null);

        $this->service->wear($user, 13);

        $this->assertSame(2, $ligneAncien->getQuantity(), "L'ancien objet doit s'empiler, pas créer une 2e ligne.");
        $this->assertNull(
            $this->persistedOrNull(InventaireEquipement::class),
            "Aucune ligne de sac ne doit être créée quand la pile existe déjà."
        );
    }

    /* ---------------------------------------------------------------- */
    /* Piles                                                             */
    /* ---------------------------------------------------------------- */

    public function testWearDecrementeLaPileSansSupprimerLaLigne(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $equipement = $this->makeEquipement(2, 1);
        $ligne = $this->makeLigneSac($inventaire, $equipement, 3);

        $this->equipementRepository->method('find')->willReturn($equipement);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')->willReturn($ligne);
        $this->userEquipementRepository->method('getEquipementEquipeByUserAndPosition')->willReturn(null);

        $this->service->wear($user, 2);

        $this->assertSame(2, $ligne->getQuantity());
        $this->assertNotContains($ligne, $this->removed);
    }

    public function testUnwearRemetLObjetDansLeSac(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $equipement = $this->makeEquipement(5, 7);
        $porte = $this->makeUserEquipement($user, $equipement);

        $this->userEquipementRepository->method('findOneBy')->willReturn($porte);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')->willReturn(null);
        $this->equipementRepository->method('find')->willReturn($equipement);

        $this->service->unwear($user, 5);

        $this->assertContains($porte, $this->removed);
        $this->assertSame($equipement, $this->onlyPersisted(InventaireEquipement::class)->getEquipement());
    }

    /* ---------------------------------------------------------------- */
    /* Bonus de caractéristiques                                         */
    /* ---------------------------------------------------------------- */

    public function testWearAppliqueLesBonusEtUnwearLesRetire(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $caracteristique = $this->makeCaracteristique(1, 'constitution');
        $equipement = $this->makeEquipement(5, 7, [[$caracteristique, 4]]);
        $bonus = $this->makeBonus($user, $caracteristique, 10);

        $this->equipementRepository->method('find')->willReturn($equipement);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')
            ->willReturn($this->makeLigneSac($inventaire, $equipement, 1));
        $this->userEquipementRepository->method('getEquipementEquipeByUserAndPosition')->willReturn(null);
        $this->joueurCaracteristiqueBonusRepository->method('findOneBy')->willReturn($bonus);

        $this->service->wear($user, 5);
        $this->assertSame(14, $bonus->getPoints());

        $this->userEquipementRepository->method('findOneBy')->willReturn($this->makeUserEquipement($user, $equipement));
        $this->service->unwear($user, 5);
        $this->assertSame(10, $bonus->getPoints());
    }

    public function testWearCreeLaLigneDeBonusManquante(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $caracteristique = $this->makeCaracteristique(7, 'armure');
        $equipement = $this->makeEquipement(5, 7, [[$caracteristique, 4]]);

        $this->equipementRepository->method('find')->willReturn($equipement);
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')
            ->willReturn($this->makeLigneSac($inventaire, $equipement, 1));
        $this->userEquipementRepository->method('getEquipementEquipeByUserAndPosition')->willReturn(null);
        $this->joueurCaracteristiqueBonusRepository->method('findOneBy')->willReturn(null);

        $this->service->wear($user, 5);

        $bonus = $this->onlyPersisted(JoueurCaracteristiqueBonus::class);
        $this->assertSame($caracteristique, $bonus->getCaracteristique());
        $this->assertSame(4, $bonus->getPoints());
    }

    public function testUnwearNeDescendJamaisUnBonusSousZero(): void
    {
        $user = $this->makeUser(1);
        $inventaire = $this->makeInventaire();
        $caracteristique = $this->makeCaracteristique(1, 'constitution');
        $equipement = $this->makeEquipement(5, 7, [[$caracteristique, 4]]);
        $bonus = $this->makeBonus($user, $caracteristique, 1); // état déjà incohérent

        $this->userEquipementRepository->method('findOneBy')->willReturn($this->makeUserEquipement($user, $equipement));
        $this->inventaireRepository->method('findOneBy')->willReturn($inventaire);
        $this->inventaireEquipementRepository->method('findOneBy')->willReturn(null);
        $this->equipementRepository->method('find')->willReturn($equipement);
        $this->joueurCaracteristiqueBonusRepository->method('findOneBy')->willReturn($bonus);

        $this->service->unwear($user, 5);

        $this->assertSame(0, $bonus->getPoints());
    }

    /* ---------------------------------------------------------------- */
    /* Fabriques                                                         */
    /* ---------------------------------------------------------------- */

    /** @template T of object @param class-string<T> $class @return T */
    private function onlyPersisted(string $class): object
    {
        $found = array_values(array_filter($this->persisted, fn ($entity) => $entity instanceof $class));
        $this->assertCount(1, $found, "Une seule entité $class attendue en persist().");

        return $found[0];
    }

    private function persistedOrNull(string $class): ?object
    {
        $found = array_values(array_filter($this->persisted, fn ($entity) => $entity instanceof $class));

        return $found[0] ?? null;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $this->setId($user, $id);

        return $user;
    }

    private function makeInventaire(): Inventaire
    {
        $inventaire = new Inventaire();
        $this->setId($inventaire, 1);

        return $inventaire;
    }

    /** @param array<array{0: Caracteristique, 1: int}> $caracteristiques */
    private function makeEquipement(int $id, int $positionId, array $caracteristiques = []): Equipement
    {
        $position = new PositionEquipement();
        $this->setId($position, $positionId);

        $equipement = new Equipement();
        $this->setId($equipement, $id);
        $equipement->setPositionEquipement($position);

        foreach ($caracteristiques as [$caracteristique, $valeur]) {
            $equipementCaracteristique = new EquipementCaracteristique();
            $equipementCaracteristique->setCaracteristique($caracteristique);
            $equipementCaracteristique->setValeur($valeur);
            $equipement->addEquipementCaracteristique($equipementCaracteristique);
        }

        return $equipement;
    }

    private function makeCaracteristique(int $id, string $nom): Caracteristique
    {
        $caracteristique = new Caracteristique();
        $this->setId($caracteristique, $id);
        $caracteristique->setNom($nom);

        return $caracteristique;
    }

    private function makeLigneSac(Inventaire $inventaire, Equipement $equipement, int $quantity): InventaireEquipement
    {
        $ligne = new InventaireEquipement();
        $ligne->setInventaire($inventaire);
        $ligne->setEquipement($equipement);
        $ligne->setQuantity($quantity);

        return $ligne;
    }

    private function makeUserEquipement(User $user, Equipement $equipement): UserEquipement
    {
        $userEquipement = new UserEquipement();
        $userEquipement->setUser($user);
        $userEquipement->setEquipement($equipement);

        return $userEquipement;
    }

    private function makeBonus(User $user, Caracteristique $caracteristique, int $points): JoueurCaracteristiqueBonus
    {
        $bonus = new JoueurCaracteristiqueBonus();
        $bonus->setJoueur($user);
        $bonus->setCaracteristique($caracteristique);
        $bonus->setPoints($points);

        return $bonus;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
