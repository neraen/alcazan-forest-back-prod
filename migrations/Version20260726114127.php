<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artisanat, lot 1 : les ressources et le karma.
 *
 * Une ressource est un `objet` rattaché à un métier — pas une entité à part, sans quoi
 * inventaire, échange, boutique, butin et récompenses seraient tous à réoutiller.
 *
 * Les deux colonnes NOT NULL portent un DEFAULT : sans lui, MySQL en mode strict refuse
 * l'ajout dès que la table contient des lignes (piège rencontré au lot 0).
 */
final class Version20260726114127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artisanat lot 1 : objet.metier_id + niveau_ressource, user.karma';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objet ADD metier_id INT DEFAULT NULL, ADD niveau_ressource INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE objet ADD CONSTRAINT FK_46CD4C38ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('CREATE INDEX IDX_46CD4C38ED16FA20 ON objet (metier_id)');
        $this->addSql('ALTER TABLE user ADD karma INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE objet DROP FOREIGN KEY FK_46CD4C38ED16FA20');
        $this->addSql('DROP INDEX IDX_46CD4C38ED16FA20 ON objet');
        $this->addSql('ALTER TABLE objet DROP metier_id, DROP niveau_ressource');
        $this->addSql('ALTER TABLE user DROP karma');
    }
}
