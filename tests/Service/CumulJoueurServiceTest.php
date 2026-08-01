<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\TypeCumul;
use App\Repository\JoueurCumulRepository;
use App\service\CumulJoueurService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Cumuls de partie : garde-fous d'entrée et mise en forme pour l'affichage.
 * L'upsert lui-même est du SQL et se vérifie en fonctionnel — ici on couvre les décisions
 * que le service prend AVANT d'écrire. Même découpage que `CompteurJoueurServiceTest`.
 */
class CumulJoueurServiceTest extends TestCase
{
    private JoueurCumulRepository|MockObject $repository;
    private CumulJoueurService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(JoueurCumulRepository::class);
        $this->service = new CumulJoueurService($this->repository);
    }

    /* ---------------------------------------------------------------- */
    /* Écriture                                                          */
    /* ---------------------------------------------------------------- */

    public function testAjouterDelegueAuRepository(): void
    {
        $user = $this->joueur(12);

        $this->repository->expects($this->once())
            ->method('ajouterParId')
            ->with(12, TypeCumul::MONSTRES_TUES, 1)
            ->willReturn(38);

        $this->assertSame(38, $this->service->ajouter($user, TypeCumul::MONSTRES_TUES));
    }

    public function testUnPasExpliciteEstTransmis(): void
    {
        $user = $this->joueur(12);

        $this->repository->expects($this->once())
            ->method('ajouterParId')
            ->with(12, TypeCumul::XP_TOTALE, 240)
            ->willReturn(240);

        $this->assertSame(240, $this->service->ajouter($user, TypeCumul::XP_TOTALE, 240));
    }

    /**
     * Un cumul ne redescend jamais : c'est un fait de partie, pas un solde.
     *
     * Le cas n'est pas théorique — `LevelingService::giveExpMalusAfterDeath` fait passer une
     * valeur NÉGATIVE par le point de passage de l'XP. Un malus de mort n'est pas de l'XP
     * « dé-gagnée », et le laisser décrémenter fausserait aussi bien la fiche que le classement.
     */
    public function testUnPasNegatifNEcritRienEtRenvoieLaValeurCourante(): void
    {
        $user = $this->joueur(12);

        $this->repository->expects($this->never())->method('ajouterParId');
        $this->repository->expects($this->once())
            ->method('valeurParId')
            ->with(12, TypeCumul::XP_TOTALE)
            ->willReturn(1500);

        $this->assertSame(1500, $this->service->ajouter($user, TypeCumul::XP_TOTALE, -900));
    }

    public function testUnPasNulNEcritRien(): void
    {
        $this->repository->expects($this->never())->method('ajouterParId');
        $this->repository->method('valeurParId')->willReturn(7);

        $this->assertSame(7, $this->service->ajouter($this->joueur(12), TypeCumul::OR_GAGNE, 0));
    }

    /** Un joueur non persisté n'a pas d'identifiant : on n'écrit pas une ligne orpheline. */
    public function testUnJoueurSansIdentifiantNEcritRien(): void
    {
        $this->repository->expects($this->never())->method('ajouterParId');

        $this->assertSame(0, $this->service->ajouter(new User(), TypeCumul::MORTS));
    }

    /**
     * `ajouterParId` existe pour `LevelingService`, qui ne reçoit qu'un identifiant : charger
     * l'entité juste pour relire son id serait un aller-retour base sur un chemin chaud.
     */
    public function testAjouterParIdNeDemandePasDEntite(): void
    {
        $this->repository->expects($this->once())
            ->method('ajouterParId')
            ->with(44, TypeCumul::XP_TOTALE, 180)
            ->willReturn(180);

        $this->assertSame(180, $this->service->ajouterParId(44, TypeCumul::XP_TOTALE, 180));
    }

    /* ---------------------------------------------------------------- */
    /* Lecture et mise en forme                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Une clé jamais alimentée vaut 0 et reste AFFICHÉE : une fiche de personnage neuf doit
     * annoncer « 0 monstre vaincu », pas escamoter la ligne.
     */
    public function testDecrireRendLesClesJamaisAlimenteesAZero(): void
    {
        $user = $this->joueur(12);
        $this->repository->method('valeurs')->willReturn(['xp_totale' => 4123]);

        $lignes = $this->service->decrire($user, [TypeCumul::XP_TOTALE, TypeCumul::BOSS_VAINCUS]);

        $this->assertSame(4123, $lignes[0]['valeur']);
        $this->assertSame(0, $lignes[1]['valeur']);
        $this->assertSame('boss_vaincus', $lignes[1]['cle']);
    }

    /** Libellé, unité et format viennent du serveur : le front ne connaît aucune clé en dur. */
    public function testDecrirePorteLeLibelleEtLeFormat(): void
    {
        $this->repository->method('valeurs')->willReturn(['or_gagne' => 950]);

        $lignes = $this->service->decrire($this->joueur(12), [TypeCumul::OR_GAGNE]);

        $this->assertSame('Or gagné', $lignes[0]['label']);
        $this->assertSame('or', $lignes[0]['format']);
        $this->assertNotSame('', $lignes[0]['unite']);
    }

    public function testDecrireSansFiltreRendTousLesCumuls(): void
    {
        $this->repository->method('valeurs')->willReturn([]);

        $this->assertCount(
            count(TypeCumul::cases()),
            $this->service->decrire($this->joueur(12))
        );
    }

    /**
     * Les flux d'or sont volontairement absents de la fiche : elle montre déjà la richesse
     * COURANTE, et les afficher côte à côte inviterait à les additionner alors qu'ils ne se
     * composent pas.
     */
    public function testLesFaitsDArmesExcluentLesFluxDOr(): void
    {
        $cles = array_map(static fn (TypeCumul $cle) => $cle->value, TypeCumul::faitsDArmes());

        $this->assertNotContains('or_gagne', $cles);
        $this->assertNotContains('or_depense', $cles);
        $this->assertContains('xp_totale', $cles);
    }

    /* ---------------------------------------------------------------- */

    private function joueur(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id);

        return $user;
    }
}
