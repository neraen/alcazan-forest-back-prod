<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Système d'échange joueur-à-joueur : tables `echange` (session : participants, statuts, or
 * proposé, confirmations, version optimiste, expiration) et `echange_ligne` (items proposés,
 * une ligne par (échange, joueur, item)). Tables runtime : elles sont dans la liste noire de
 * scripts/content-dump.sh et ne partent jamais dans le seed de contenu.
 */
final class Version20260724193640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tables echange et echange_ligne (sessions d'échange joueur-à-joueur)";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE echange (id INT AUTO_INCREMENT NOT NULL, joueur_un_id INT NOT NULL, joueur_deux_id INT NOT NULL, annule_par_id INT DEFAULT NULL, statut VARCHAR(20) NOT NULL, or_joueur_un INT NOT NULL, or_joueur_deux INT NOT NULL, confirme_joueur_un TINYINT(1) NOT NULL, confirme_joueur_deux TINYINT(1) NOT NULL, version INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', cancelled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_B577E3BF6D5CBDB6 (joueur_un_id), INDEX IDX_B577E3BF58F2A694 (joueur_deux_id), INDEX IDX_B577E3BFF376B95 (annule_par_id), INDEX idx_echange_statut (statut), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE echange_ligne (id INT AUTO_INCREMENT NOT NULL, echange_id INT NOT NULL, proprietaire_id INT NOT NULL, type VARCHAR(20) NOT NULL, item_id INT NOT NULL, quantite INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_6C5D8F6E13713818 (echange_id), INDEX IDX_6C5D8F6E76C50E4A (proprietaire_id), UNIQUE INDEX uniq_echange_ligne (echange_id, proprietaire_id, type, item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE echange ADD CONSTRAINT FK_B577E3BF6D5CBDB6 FOREIGN KEY (joueur_un_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE echange ADD CONSTRAINT FK_B577E3BF58F2A694 FOREIGN KEY (joueur_deux_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE echange ADD CONSTRAINT FK_B577E3BFF376B95 FOREIGN KEY (annule_par_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE echange_ligne ADD CONSTRAINT FK_6C5D8F6E13713818 FOREIGN KEY (echange_id) REFERENCES echange (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE echange_ligne ADD CONSTRAINT FK_6C5D8F6E76C50E4A FOREIGN KEY (proprietaire_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE echange DROP FOREIGN KEY FK_B577E3BF6D5CBDB6');
        $this->addSql('ALTER TABLE echange DROP FOREIGN KEY FK_B577E3BF58F2A694');
        $this->addSql('ALTER TABLE echange DROP FOREIGN KEY FK_B577E3BFF376B95');
        $this->addSql('ALTER TABLE echange_ligne DROP FOREIGN KEY FK_6C5D8F6E13713818');
        $this->addSql('ALTER TABLE echange_ligne DROP FOREIGN KEY FK_6C5D8F6E76C50E4A');
        $this->addSql('DROP TABLE echange');
        $this->addSql('DROP TABLE echange_ligne');
    }
}
