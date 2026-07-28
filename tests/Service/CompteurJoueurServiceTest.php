<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\TypeCompteur;
use App\Repository\CompteurJoueurRepository;
use App\service\CompteurJoueurService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Compteurs de progression : garde-fous d'entrée et arithmétique de la progression.
 * L'upsert lui-même est du SQL et se vérifie en fonctionnel — ici on couvre les
 * décisions que le service prend AVANT d'écrire.
 */
class CompteurJoueurServiceTest extends TestCase
{
    private CompteurJoueurRepository|MockObject $repository;
    private CompteurJoueurService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CompteurJoueurRepository::class);
        $this->service = new CompteurJoueurService($this->repository);
    }

    public function testIncrementerDelegueAuRepository(): void
    {
        $user = new User();

        $this->repository->expects($this->once())
            ->method('incrementer')
            ->with($user, TypeCompteur::MONSTRE_TUE, 7, 1)
            ->willReturn(4);

        $this->assertSame(4, $this->service->incrementer($user, TypeCompteur::MONSTRE_TUE, 7));
    }

    /**
     * Un compteur ne redescend JAMAIS : c'est un fait de partie, pas un état de quête.
     * Un pas nul ou négatif est donc ignoré plutôt que propagé — sans quoi un appelant
     * distrait pourrait effacer la progression d'un joueur.
     */
    public function testUnPasNulOuNegatifNEcritRien(): void
    {
        $this->repository->expects($this->never())->method('incrementer');
        $this->repository->method('valeur')->willReturn(12);

        $user = new User();
        $this->assertSame(12, $this->service->incrementer($user, TypeCompteur::MONSTRE_TUE, 7, 0));
        $this->assertSame(12, $this->service->incrementer($user, TypeCompteur::MONSTRE_TUE, 7, -5));
    }

    /** Cible absente (action mal configurée) : aucune écriture, aucune exception. */
    public function testUneCibleAbsenteNEcritRien(): void
    {
        $this->repository->expects($this->never())->method('incrementer');
        $this->repository->expects($this->never())->method('valeur');

        $this->assertSame(0, $this->service->incrementer(new User(), TypeCompteur::OBJET_FABRIQUE, 0));
        $this->assertSame(0, $this->service->valeur(new User(), TypeCompteur::OBJET_FABRIQUE, 0));
    }

    public function testLaProgressionSeCompteDepuisLInstantane(): void
    {
        $this->repository->method('valeur')->willReturn(53);

        $this->assertSame(3, $this->service->progression(new User(), TypeCompteur::MONSTRE_TUE, 7, 50));
    }

    /**
     * Un départ postérieur à la valeur courante n'est pas une anomalie de code : il
     * suffit que l'administrateur ait rebranché l'action sur une autre cible entre
     * l'instantané et la relecture. Afficher « -3 / 10 » au joueur serait pire que
     * de repartir de zéro.
     */
    public function testLaProgressionNeDescendPasSousZero(): void
    {
        $this->repository->method('valeur')->willReturn(2);

        $this->assertSame(0, $this->service->progression(new User(), TypeCompteur::MONSTRE_TUE, 7, 50));
    }

    /** La clé d'instantané est stable : c'est elle qui est écrite en base. */
    public function testLaCleDInstantaneEstStable(): void
    {
        $this->assertSame('monstre_tue:12', TypeCompteur::MONSTRE_TUE->cle(12));
        $this->assertSame('ressource_recoltee:4', TypeCompteur::RESSOURCE_RECOLTEE->cle(4));
    }
}
