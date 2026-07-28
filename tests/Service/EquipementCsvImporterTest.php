<?php

namespace App\Tests\Service;

use App\Entity\Caracteristique;
use App\Entity\Classe;
use App\Entity\Equipement;
use App\Entity\PositionEquipement;
use App\Entity\Rarity;
use App\Repository\CaracteristiqueRepository;
use App\Repository\ClasseRepository;
use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\EquipementRepository;
use App\Repository\PositionEquipementRepository;
use App\Repository\RarityRepository;
use App\service\EquipementCsvImporter;
use App\service\EquipementIconeUploader;
use App\service\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Import CSV d'équipements : la lecture du fichier (séparateur, BOM, alias, référentiels
 * résolus par nom) est ce qui casse en silence, et c'est justement ce qui s'isole sans base.
 */
class EquipementCsvImporterTest extends TestCase
{
    /** Équipements passés à persist(), indexés par nom. @var array<string, Equipement> */
    private array $persistes = [];

    /** @var list<string> fichiers temporaires à nettoyer */
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $fichier) {
            @unlink($fichier);
        }
    }

    /** Export Excel français typique : BOM, point-virgule, accents. */
    public function testImporteUnCsvPointVirguleAvecBom(): void
    {
        $rapport = $this->importeur()->importer($this->csv(
            true,
            'nom;description;position;rarete;prix achat;prix revente;niveau min;classes;force;armure;Vol de vie',
            'Cape des Brumes;Tissée dans la brume.;corps;rare;450;120;12;;0;14;0'
        ));

        $this->assertSame(1, $rapport['crees']);
        $this->assertSame('cree', $rapport['lignes'][0]['statut']);

        $equipement = $this->persistes['Cape des Brumes'];
        $this->assertSame('Tissée dans la brume.', $equipement->getDescription());
        $this->assertSame('corps', $equipement->getPositionEquipement()->getName());
        $this->assertSame('rare', $equipement->getRarity()->getName());
        $this->assertSame(450, $equipement->getPrixAchat());
        $this->assertSame(120, $equipement->getPrixRevente());
        $this->assertSame(12, $equipement->getLevelMin());
    }

    /** Export Google Sheets : virgule, et une virgule protégée par des guillemets. */
    public function testImporteUnCsvVirguleAvecCelluleEchappee(): void
    {
        $rapport = $this->importeur()->importer($this->csv(
            false,
            'nom,description,position,rarete',
            '"Botte du Marais","Boueuse, mais solide",corps,commun'
        ));

        $this->assertSame(1, $rapport['crees']);
        $this->assertSame('Boueuse, mais solide', $this->persistes['Botte du Marais']->getDescription());
    }

    /** Les en-têtes sont tolérants : casse et alias. */
    public function testReconnaitLesAliasDeColonnes(): void
    {
        $this->importeur()->importer($this->csv(
            false,
            'Name;Emplacement;Rarity;Achat;Niveau',
            'Anneau de Tourbe;corps;commun;80;7'
        ));

        $this->assertSame(80, $this->persistes['Anneau de Tourbe']->getPrixAchat());
        $this->assertSame(7, $this->persistes['Anneau de Tourbe']->getLevelMin());
    }

    /** Une colonne hors référentiel est signalée, pas fatale. */
    public function testSignaleLesColonnesInconnues(): void
    {
        $rapport = $this->importeur()->importer($this->csv(false, 'nom;position;couleur', 'Cape;corps;vert'));

        $this->assertSame(['couleur'], $rapport['colonnesIgnorees']);
        $this->assertSame(1, $rapport['crees']);
    }

    /** Cellule vide = aucune restriction : c'est la convention du jeu, pas un oubli. */
    public function testUneCelluleClassesVideNeRestreintPersonne(): void
    {
        $this->importeur()->importer($this->csv(
            false,
            'nom;position;classes',
            'Cape;corps;',
            'Dague;bras-droit;gueux|archer'
        ));

        $this->assertCount(0, $this->persistes['Cape']->getClasse());
        $this->assertCount(2, $this->persistes['Dague']->getClasse());
    }

    /** Une ligne fautive est rapportée et sautée ; les autres passent quand même. */
    public function testUnePositionInconnueNeFaitPasEchouerLeFichier(): void
    {
        $rapport = $this->importeur()->importer($this->csv(
            false,
            'nom;position',
            'Heaume Fantôme;chapeau',
            'Cape des Brumes;corps'
        ));

        $this->assertSame(1, $rapport['crees']);
        $this->assertSame(1, $rapport['ignores']);
        $this->assertSame('erreur', $rapport['lignes'][0]['statut']);
        $this->assertStringContainsString('chapeau', $rapport['lignes'][0]['message']);
        $this->assertSame('cree', $rapport['lignes'][1]['statut']);
        $this->assertArrayNotHasKey('Heaume Fantôme', $this->persistes);
    }

    public function testUneLigneSansNomEstRefusee(): void
    {
        $rapport = $this->importeur()->importer($this->csv(false, 'nom;position', ';corps'));

        $this->assertSame(0, $rapport['crees']);
        $this->assertSame('Nom manquant.', $rapport['lignes'][0]['message']);
    }

    /** Deux lignes homonymes visent le même objet : l'entité n'a pas d'unicité sur le nom. */
    public function testDeuxLignesDuMemeNomNeFontQuUnEquipement(): void
    {
        $rapport = $this->importeur()->importer($this->csv(
            false,
            'nom;position;prix achat',
            'Cape;corps;10',
            'Cape;corps;90'
        ));

        $this->assertSame(1, $rapport['crees']);
        $this->assertSame(1, $rapport['misAJour']);
        $this->assertCount(1, $this->persistes);
        $this->assertSame(90, $this->persistes['Cape']->getPrixAchat());
    }

    /** Sans mise à jour autorisée, un homonyme existant est laissé intact. */
    public function testSansMiseAJourUnHomonymeExistantEstIgnore(): void
    {
        $existant = (new Equipement())->setNom('Cape')->setPrixAchat(10);

        $rapport = $this->importeur($existant)->importer(
            $this->csv(false, 'nom;position;prix achat', 'Cape;corps;90'),
            false
        );

        $this->assertSame(0, $rapport['crees']);
        $this->assertSame(1, $rapport['ignores']);
        $this->assertSame('ignore', $rapport['lignes'][0]['statut']);
        $this->assertSame(10, $existant->getPrixAchat());
    }

    /**
     * Le flux visé est « import CSV puis upload des images » : une cellule `icone` vide sur une
     * mise à jour ne doit surtout pas effacer l'image déjà accrochée à l'objet.
     */
    public function testUneColonneIconeVideNEffacePasLImageExistante(): void
    {
        $existant = (new Equipement())->setNom('Cape')->setIcone('cape-des-brumes.png');

        $rapport = $this->importeur($existant)->importer($this->csv(false, 'nom;position;icone', 'Cape;corps;'));

        $this->assertSame(1, $rapport['misAJour']);
        $this->assertSame('cape-des-brumes.png', $existant->getIcone());
    }

    /** Le rapport sert à préparer l'upload des images : il donne le chemin attendu. */
    public function testLeRapportDonneLeCheminDImageAttendu(): void
    {
        $rapport = $this->importeur()->importer($this->csv(false, 'nom;position', 'Épée du Culte de Rhog;bras-droit'));

        $this->assertSame('img/equipement/bras-droit/epee-du-culte-de-rhog.png', $rapport['lignes'][0]['image']);
    }

    public function testUnFichierVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->importeur()->importer($this->csv(false, '   '));
    }

    public function testUnEnteteSansColonneNomEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nom');

        $this->importeur()->importer($this->csv(false, 'position;rarete', 'corps;commun'));
    }

    // ---------------------------------------------------------------- outillage

    /** @param Equipement|null $existant équipement que le dépôt renverra pour son propre nom */
    private function importeur(?Equipement $existant = null): EquipementCsvImporter
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function ($entite): void {
            if ($entite instanceof Equipement) {
                $this->persistes[$entite->getNom()] = $entite;
            }
        });

        $equipementRepository = $this->createMock(EquipementRepository::class);
        $equipementRepository->method('findOneBy')->willReturnCallback(
            fn(array $criteres) => $existant !== null && ($criteres['nom'] ?? null) === $existant->getNom()
                ? $existant
                : null
        );

        $caracteristiqueRepository = $this->createMock(CaracteristiqueRepository::class);
        $caracteristiqueRepository->method('findAll')->willReturn([
            $this->avecId(new Caracteristique(), 1)->setNom('force'),
            $this->avecId(new Caracteristique(), 2)->setNom('armure'),
            $this->avecId(new Caracteristique(), 3)->setNom('Vol de vie'),
        ]);

        $positionRepository = $this->createMock(PositionEquipementRepository::class);
        $positionRepository->method('findAll')->willReturn([
            $this->avecId(new PositionEquipement(), 1)->setName('bras-droit'),
            $this->avecId(new PositionEquipement(), 3)->setName('corps'),
        ]);

        $rarityRepository = $this->createMock(RarityRepository::class);
        $rarityRepository->method('findAll')->willReturn([
            $this->avecId(new Rarity(), 1)->setName('commun'),
            $this->avecId(new Rarity(), 3)->setName('rare'),
        ]);

        $classeRepository = $this->createMock(ClasseRepository::class);
        $classeRepository->method('findAll')->willReturn([
            $this->avecId(new Classe(), 3)->setNom('gueux'),
            $this->avecId(new Classe(), 4)->setNom('archer'),
        ]);

        return new EquipementCsvImporter(
            $entityManager,
            $equipementRepository,
            $this->createMock(EquipementCaracteristiqueRepository::class),
            $positionRepository,
            $rarityRepository,
            $classeRepository,
            $caracteristiqueRepository,
            new EquipementIconeUploader(new ImageUploader(new AsciiSlugger(), sys_get_temp_dir()))
        );
    }

    /** Écrit les lignes dans un fichier temporaire et l'emballe en UploadedFile de test. */
    private function csv(bool $avecBom, string ...$lignes): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'csv');
        $this->fichiers[] = $chemin;
        file_put_contents($chemin, ($avecBom ? "\xEF\xBB\xBF" : '') . implode("\n", $lignes));

        return new UploadedFile($chemin, 'equipements.csv', 'text/csv', null, true);
    }

    /** Les entités de référentiel ont besoin d'un id : les collections N-N s'indexent dessus. */
    private function avecId(object $entite, int $id): object
    {
        (new \ReflectionProperty($entite::class, 'id'))->setValue($entite, $id);

        return $entite;
    }
}
