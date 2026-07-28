<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artisanat, lot 5 : le dépeceur — une ligne de butin peut exiger un métier.
 *
 * `metier_id` nul et les deux compteurs à 0 : toutes les lignes existantes tombent
 * exactement comme avant.
 */
final class Version20260726124437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artisanat lot 5 : monstre_objet.metier_id + niveau/experience de metier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monstre_objet ADD metier_id INT DEFAULT NULL, ADD niveau_metier_min INT DEFAULT 0 NOT NULL, ADD experience_metier INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE monstre_objet ADD CONSTRAINT FK_E432EA0FED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('CREATE INDEX IDX_E432EA0FED16FA20 ON monstre_objet (metier_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monstre_objet DROP FOREIGN KEY FK_E432EA0FED16FA20');
        $this->addSql('DROP INDEX IDX_E432EA0FED16FA20 ON monstre_objet');
        $this->addSql('ALTER TABLE monstre_objet DROP metier_id, DROP niveau_metier_min, DROP experience_metier');
    }
}
