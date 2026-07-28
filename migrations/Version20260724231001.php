<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724231001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Donjons : contenu (donjon, donjon_salle) + runtime (instance, membre, verrou).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE donjon (id INT AUTO_INCREMENT NOT NULL, carte_sortie_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, icone VARCHAR(255) DEFAULT NULL, niveau_min INT NOT NULL, taille_groupe_max INT NOT NULL, duree_max_minutes INT NOT NULL, heure_reset INT NOT NULL, actif TINYINT(1) NOT NULL, sortie_abscisse INT NOT NULL, sortie_ordonnee INT NOT NULL, INDEX IDX_EF13F9822B411B24 (carte_sortie_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_instance (id INT AUTO_INCREMENT NOT NULL, donjon_id INT NOT NULL, leader_id INT NOT NULL, statut VARCHAR(32) NOT NULL, boss_current_life INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expire_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7553FC12C530111B (donjon_id), INDEX IDX_7553FC1273154ED4 (leader_id), INDEX idx_donjon_instance_statut (statut), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_instance_membre (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, user_id INT NOT NULL, present TINYINT(1) NOT NULL, joined_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_BEC4AD9A3A51721D (instance_id), INDEX IDX_BEC4AD9AA76ED395 (user_id), UNIQUE INDEX uniq_donjon_membre (instance_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_salle (id INT AUTO_INCREMENT NOT NULL, donjon_id INT NOT NULL, carte_id INT NOT NULL, ordre INT NOT NULL, type VARCHAR(32) NOT NULL, INDEX IDX_32E17AD3C530111B (donjon_id), UNIQUE INDEX uniq_donjon_salle_carte (carte_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_verrou (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, donjon_id INT NOT NULL, instance_id INT NOT NULL, jour_reset DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_14597905A76ED395 (user_id), INDEX IDX_14597905C530111B (donjon_id), INDEX IDX_145979053A51721D (instance_id), UNIQUE INDEX uniq_donjon_verrou_jour (user_id, donjon_id, jour_reset), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE donjon ADD CONSTRAINT FK_EF13F9822B411B24 FOREIGN KEY (carte_sortie_id) REFERENCES carte (id)');
        $this->addSql('ALTER TABLE donjon_instance ADD CONSTRAINT FK_7553FC12C530111B FOREIGN KEY (donjon_id) REFERENCES donjon (id)');
        $this->addSql('ALTER TABLE donjon_instance ADD CONSTRAINT FK_7553FC1273154ED4 FOREIGN KEY (leader_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE donjon_instance_membre ADD CONSTRAINT FK_BEC4AD9A3A51721D FOREIGN KEY (instance_id) REFERENCES donjon_instance (id)');
        $this->addSql('ALTER TABLE donjon_instance_membre ADD CONSTRAINT FK_BEC4AD9AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE donjon_salle ADD CONSTRAINT FK_32E17AD3C530111B FOREIGN KEY (donjon_id) REFERENCES donjon (id)');
        $this->addSql('ALTER TABLE donjon_salle ADD CONSTRAINT FK_32E17AD3C9C7CEB6 FOREIGN KEY (carte_id) REFERENCES carte (id)');
        $this->addSql('ALTER TABLE donjon_verrou ADD CONSTRAINT FK_14597905A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE donjon_verrou ADD CONSTRAINT FK_14597905C530111B FOREIGN KEY (donjon_id) REFERENCES donjon (id)');
        $this->addSql('ALTER TABLE donjon_verrou ADD CONSTRAINT FK_145979053A51721D FOREIGN KEY (instance_id) REFERENCES donjon_instance (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE donjon DROP FOREIGN KEY FK_EF13F9822B411B24');
        $this->addSql('ALTER TABLE donjon_instance DROP FOREIGN KEY FK_7553FC12C530111B');
        $this->addSql('ALTER TABLE donjon_instance DROP FOREIGN KEY FK_7553FC1273154ED4');
        $this->addSql('ALTER TABLE donjon_instance_membre DROP FOREIGN KEY FK_BEC4AD9A3A51721D');
        $this->addSql('ALTER TABLE donjon_instance_membre DROP FOREIGN KEY FK_BEC4AD9AA76ED395');
        $this->addSql('ALTER TABLE donjon_salle DROP FOREIGN KEY FK_32E17AD3C530111B');
        $this->addSql('ALTER TABLE donjon_salle DROP FOREIGN KEY FK_32E17AD3C9C7CEB6');
        $this->addSql('ALTER TABLE donjon_verrou DROP FOREIGN KEY FK_14597905A76ED395');
        $this->addSql('ALTER TABLE donjon_verrou DROP FOREIGN KEY FK_14597905C530111B');
        $this->addSql('ALTER TABLE donjon_verrou DROP FOREIGN KEY FK_145979053A51721D');
        $this->addSql('DROP TABLE donjon');
        $this->addSql('DROP TABLE donjon_instance');
        $this->addSql('DROP TABLE donjon_instance_membre');
        $this->addSql('DROP TABLE donjon_salle');
        $this->addSql('DROP TABLE donjon_verrou');
    }
}
