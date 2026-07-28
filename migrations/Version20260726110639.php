<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artisanat, lot 0 : familles de métiers, apprentissage explicite, maîtres de métier.
 *
 * Les deux `ADD ... NOT NULL` demandent un rattrapage de données : MySQL remplirait
 * `famille` avec une chaîne vide, qui n'est pas un cas valide de l'enum `FamilleMetier`
 * et ferait échouer l'hydratation de tout métier existant.
 */
final class Version20260726110639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artisanat lot 0 : metier.famille, joueur_metier.appris_at, table pnj_metier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pnj_metier (pnj_id INT NOT NULL, metier_id INT NOT NULL, INDEX IDX_F5B63EDA51796E0B (pnj_id), INDEX IDX_F5B63EDAED16FA20 (metier_id), PRIMARY KEY(pnj_id, metier_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pnj_metier ADD CONSTRAINT FK_F5B63EDA51796E0B FOREIGN KEY (pnj_id) REFERENCES pnj (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pnj_metier ADD CONSTRAINT FK_F5B63EDAED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id) ON DELETE CASCADE');
        // `DATETIME NOT NULL` sans défaut est REFUSÉ par MySQL en mode strict dès que la
        // table contient des lignes (il n'a rien à y écrire). On ajoute donc la colonne
        // avec un défaut — qui remplit les progressions existantes — puis on retire ce
        // défaut pour rester conforme au mapping Doctrine.
        $this->addSql('ALTER TABLE joueur_metier ADD appris_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE joueur_metier ALTER COLUMN appris_at DROP DEFAULT');

        $this->addSql("ALTER TABLE metier ADD famille VARCHAR(16) DEFAULT 'recolte' NOT NULL");
        $this->addSql('ALTER TABLE metier ALTER COLUMN famille DROP DEFAULT');

        // Les métiers déjà en base (Herboriste, Mineur) sont des métiers de récolte : c'est
        // le seul type qui existait avant ce lot, puisque le craft n'existait pas. Le
        // défaut posé ci-dessus les a déjà rangés là, cette ligne n'est qu'un filet.
        $this->addSql("UPDATE metier SET famille = 'recolte' WHERE famille = ''");

        // Le plafond de progression passe de 100 à 200 (cf. docs/ARTISANAT_PLAN.md).
        $this->addSql('UPDATE metier SET niveau_max = 200 WHERE niveau_max = 100');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pnj_metier DROP FOREIGN KEY FK_F5B63EDA51796E0B');
        $this->addSql('ALTER TABLE pnj_metier DROP FOREIGN KEY FK_F5B63EDAED16FA20');
        $this->addSql('DROP TABLE pnj_metier');
        $this->addSql('ALTER TABLE joueur_metier DROP appris_at');
        $this->addSql('ALTER TABLE metier DROP famille');
    }
}
