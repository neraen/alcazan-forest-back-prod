<?php

namespace App\Tests\Service;

use App\Entity\Metier;
use App\Entity\Monstre;
use App\Entity\MonstreCarreau;
use App\Entity\MonstreObjet;
use App\Entity\Objet;
use App\Entity\User;
use App\Enum\TypeItem;
use App\service\CompteurJoueurService;
use App\service\CumulJoueurService;
use App\service\DeathService;
use App\service\DonjonInstanceService;
use App\service\JournalService;
use App\service\LevelingService;
use App\service\MapService;
use App\service\MetierService;
use App\service\SacService;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Butin de monstre conditionné par un métier — le dépeceur.
 *
 * Les taux sont forcés à 100 % (taux = diviseur = 1) pour que le test porte sur la
 * CONDITION et non sur le hasard.
 */
class DeathServiceButinTest extends TestCase
{
    private SacService|MockObject $sacService;
    private MetierService|MockObject $metierService;
    private DeathService $service;

    protected function setUp(): void
    {
        $this->sacService = $this->createMock(SacService::class);
        $this->metierService = $this->createMock(MetierService::class);

        /* EntityManager CONCRET : wrapInTransaction n'est qu'une annotation @method sur
           l'interface en ORM 2.x, donc non mockable dessus (même remarque que
           VenteServiceTest et SacServiceTest). La closure est simplement exécutée : on
           teste la logique de butin, pas Doctrine. */
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(fn (callable $f) => $f($entityManager));

        $this->service = new DeathService(
            $this->createMock(MapService::class),
            $this->createMock(LevelingService::class),
            $this->sacService,
            $this->metierService,
            $this->createMock(CompteurJoueurService::class),
            $this->createMock(CumulJoueurService::class),
            $this->createMock(JournalService::class),
            $this->createMock(CarteRepository::class),
            $this->createMock(CarteCarreauRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(DonjonInstanceService::class),
            $entityManager
        );
    }

    /** Une ligne sans métier tombe pour n'importe qui : non-régression du butin ordinaire. */
    public function testUnButinSansMetierTombePourTousLesJoueurs(): void
    {
        $carreau = $this->monstreAvec([$this->drop('Dent de loup')]);
        $this->sacService->expects($this->once())->method('ajouterItem');

        $butin = $this->service->dieMonster($carreau, new User());

        $this->assertSame(['Dent de loup'], $butin);
    }

    /** Le cœur du lot : sans le métier, la peau ne tombe pas. */
    public function testUnButinDeMetierNeTombePasSansLeMetier(): void
    {
        $depeceur = (new Metier())->setNom('Dépeceur');
        $carreau = $this->monstreAvec([$this->drop('Peau brute', $depeceur, niveauMin: 1)]);
        $this->metierService->method('niveau')->willReturn(0);
        $this->sacService->expects($this->never())->method('ajouterItem');

        $butin = $this->service->dieMonster($carreau, new User());

        $this->assertSame([], $butin);
    }

    public function testUnButinDeMetierTombeEtCrediteDeLExperience(): void
    {
        $depeceur = (new Metier())->setNom('Dépeceur');
        $carreau = $this->monstreAvec([$this->drop('Peau brute', $depeceur, niveauMin: 1, xp: 15)]);
        $this->metierService->method('niveau')->willReturn(4);
        $this->metierService->expects($this->once())
            ->method('gagnerExperience')
            ->with($this->anything(), $depeceur, 15)
            ->willReturn(['niveau' => 4, 'experience' => 15, 'experienceProchainNiveau' => 500, 'niveauxGagnes' => 0]);

        $butin = $this->service->dieMonster($carreau, new User());

        $this->assertSame(['Peau brute'], $butin);
    }

    public function testUnNiveauDeMetierInsuffisantNeDonneRien(): void
    {
        $depeceur = (new Metier())->setNom('Dépeceur');
        $carreau = $this->monstreAvec([$this->drop('Cuir épais', $depeceur, niveauMin: 20)]);
        $this->metierService->method('niveau')->willReturn(5);

        $this->assertSame([], $this->service->dieMonster($carreau, new User()));
    }

    /** Les deux familles de lignes cohabitent sur le même monstre. */
    public function testSeulesLesLignesDeMetierSontFiltrees(): void
    {
        $depeceur = (new Metier())->setNom('Dépeceur');
        $carreau = $this->monstreAvec([
            $this->drop('Dent de loup'),
            $this->drop('Peau brute', $depeceur, niveauMin: 1),
        ]);
        $this->metierService->method('niveau')->willReturn(0);

        $this->assertSame(['Dent de loup'], $this->service->dieMonster($carreau, new User()));
    }

    /**
     * Le drop d'équipement n'est pas implémenté : la ligne est ignorée plutôt qu'annoncée
     * au joueur sans lui être donnée (l'ancien code la listait dans le butin).
     */
    public function testUneLigneDEquipementEstIgnoreeEtNonAnnoncee(): void
    {
        $drop = $this->drop('Épée rouillée');
        $drop->setTypeDrop('equipement');
        $carreau = $this->monstreAvec([$drop]);
        $this->sacService->expects($this->never())->method('ajouterItem');

        $this->assertSame([], $this->service->dieMonster($carreau, new User()));
    }

    /* ------------------------------------------------------------------ */

    private static int $prochainId = 1;

    private function drop(string $nom, ?Metier $metier = null, int $niveauMin = 0, int $xp = 0): MonstreObjet
    {
        $objet = (new Objet())->setName($nom);
        // Un objet non persisté n'a pas d'id, et SacService en exige un : on le pose à la
        // main plutôt que d'ouvrir une base pour un test de logique.
        $reflexion = new \ReflectionProperty(Objet::class, 'id');
        $reflexion->setAccessible(true);
        $reflexion->setValue($objet, self::$prochainId++);

        return (new MonstreObjet())
            ->setObjet($objet)
            ->setTypeDrop('objet')
            ->setTauxDrop(1)
            ->setDiviseurTauxDrop(1)
            ->setMetier($metier)
            ->setNiveauMetierMin($niveauMin)
            ->setExperienceMetier($xp);
    }

    /** @param MonstreObjet[] $drops */
    private function monstreAvec(array $drops): MonstreCarreau
    {
        $monstre = new Monstre();
        $monstre->setMaxLife(100);
        foreach ($drops as $drop) {
            $monstre->addMonstreObjet($drop);
        }

        $carreau = new MonstreCarreau();
        $carreau->setMonstre($monstre);
        $carreau->setQuantity(3);

        return $carreau;
    }
}
