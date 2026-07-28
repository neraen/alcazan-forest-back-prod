<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725090445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Donjons : groupe éphémère formé devant la porte (lobby, ne consomme aucun verrou).";
    }

    public function up(Schema $schema): void
    {
        
        $this->addSql('CREATE TABLE donjon_groupe (id INT AUTO_INCREMENT NOT NULL, donjon_id INT NOT NULL, leader_id INT NOT NULL, statut VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expire_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_79C26494C530111B (donjon_id), INDEX IDX_79C2649473154ED4 (leader_id), INDEX idx_donjon_groupe_statut (statut), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_groupe_membre (id INT AUTO_INCREMENT NOT NULL, groupe_id INT NOT NULL, user_id INT NOT NULL, joined_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_28757647A45358C (groupe_id), INDEX IDX_2875764A76ED395 (user_id), UNIQUE INDEX uniq_donjon_groupe_membre (groupe_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE donjon_groupe ADD CONSTRAINT FK_79C26494C530111B FOREIGN KEY (donjon_id) REFERENCES donjon (id)');
        $this->addSql('ALTER TABLE donjon_groupe ADD CONSTRAINT FK_79C2649473154ED4 FOREIGN KEY (leader_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE donjon_groupe_membre ADD CONSTRAINT FK_28757647A45358C FOREIGN KEY (groupe_id) REFERENCES donjon_groupe (id)');
        $this->addSql('ALTER TABLE donjon_groupe_membre ADD CONSTRAINT FK_2875764A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        
        $this->addSql('ALTER TABLE donjon_groupe DROP FOREIGN KEY FK_79C26494C530111B');
        $this->addSql('ALTER TABLE donjon_groupe DROP FOREIGN KEY FK_79C2649473154ED4');
        $this->addSql('ALTER TABLE donjon_groupe_membre DROP FOREIGN KEY FK_28757647A45358C');
        $this->addSql('ALTER TABLE donjon_groupe_membre DROP FOREIGN KEY FK_2875764A76ED395');
        $this->addSql('DROP TABLE donjon_groupe');
        $this->addSql('DROP TABLE donjon_groupe_membre');
    }
}
