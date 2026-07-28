<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725141859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Cases interactives (ressources, coffres, leviers) + métiers.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE interaction (id INT AUTO_INCREMENT NOT NULL, recompense_id INT DEFAULT NULL, metier_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, type VARCHAR(32) NOT NULL, skin VARCHAR(255) DEFAULT NULL, message_succes VARCHAR(255) DEFAULT NULL, cout_pa INT NOT NULL, effect VARCHAR(64) DEFAULT NULL, effect_params JSON DEFAULT NULL, niveau_metier_min INT NOT NULL, experience_metier INT NOT NULL, cooldown_secondes INT NOT NULL, portee_recharge VARCHAR(16) NOT NULL, usage_unique TINYINT(1) NOT NULL, actif TINYINT(1) NOT NULL, INDEX IDX_378DFDA74D714096 (recompense_id), INDEX IDX_378DFDA7ED16FA20 (metier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE interaction_condition (id INT AUTO_INCREMENT NOT NULL, interaction_id INT NOT NULL, type VARCHAR(32) NOT NULL, params JSON NOT NULL, INDEX idx_interaction_condition (interaction_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE interaction_recharge (id INT AUTO_INCREMENT NOT NULL, carte_carreau_id INT NOT NULL, cle VARCHAR(64) NOT NULL, utilisee_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', disponible_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8CA538A8F0CC4EC8 (carte_carreau_id), UNIQUE INDEX uniq_interaction_recharge (carte_carreau_id, cle), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE joueur_metier (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, metier_id INT NOT NULL, niveau INT NOT NULL, experience INT NOT NULL, INDEX IDX_B81B1D00A76ED395 (user_id), INDEX IDX_B81B1D00ED16FA20 (metier_id), UNIQUE INDEX uniq_joueur_metier (user_id, metier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE metier (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, icone VARCHAR(255) DEFAULT NULL, niveau_max INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE interaction ADD CONSTRAINT FK_378DFDA74D714096 FOREIGN KEY (recompense_id) REFERENCES recompense (id)');
        $this->addSql('ALTER TABLE interaction ADD CONSTRAINT FK_378DFDA7ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('ALTER TABLE interaction_condition ADD CONSTRAINT FK_798D17EC886DEE8F FOREIGN KEY (interaction_id) REFERENCES interaction (id)');
        $this->addSql('ALTER TABLE interaction_recharge ADD CONSTRAINT FK_8CA538A8F0CC4EC8 FOREIGN KEY (carte_carreau_id) REFERENCES carte_carreau (id)');
        $this->addSql('ALTER TABLE joueur_metier ADD CONSTRAINT FK_B81B1D00A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE joueur_metier ADD CONSTRAINT FK_B81B1D00ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('ALTER TABLE carte_carreau ADD interaction_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE carte_carreau ADD CONSTRAINT FK_E5E3BC85886DEE8F FOREIGN KEY (interaction_id) REFERENCES interaction (id)');
        $this->addSql('CREATE INDEX IDX_E5E3BC85886DEE8F ON carte_carreau (interaction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE carte_carreau DROP FOREIGN KEY FK_E5E3BC85886DEE8F');
        $this->addSql('ALTER TABLE interaction DROP FOREIGN KEY FK_378DFDA74D714096');
        $this->addSql('ALTER TABLE interaction DROP FOREIGN KEY FK_378DFDA7ED16FA20');
        $this->addSql('ALTER TABLE interaction_condition DROP FOREIGN KEY FK_798D17EC886DEE8F');
        $this->addSql('ALTER TABLE interaction_recharge DROP FOREIGN KEY FK_8CA538A8F0CC4EC8');
        $this->addSql('ALTER TABLE joueur_metier DROP FOREIGN KEY FK_B81B1D00A76ED395');
        $this->addSql('ALTER TABLE joueur_metier DROP FOREIGN KEY FK_B81B1D00ED16FA20');
        $this->addSql('DROP TABLE interaction');
        $this->addSql('DROP TABLE interaction_condition');
        $this->addSql('DROP TABLE interaction_recharge');
        $this->addSql('DROP TABLE joueur_metier');
        $this->addSql('DROP TABLE metier');
        $this->addSql('DROP INDEX IDX_E5E3BC85886DEE8F ON carte_carreau');
        $this->addSql('ALTER TABLE carte_carreau DROP interaction_id');
    }
}
