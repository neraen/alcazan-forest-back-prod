<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725111124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Donjons : conditions de passage entre salles + population par instance.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE donjon_instance_salle (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, salle_id INT NOT NULL, peuplee TINYINT(1) NOT NULL, ouverte TINYINT(1) NOT NULL, INDEX IDX_8CF786FB3A51721D (instance_id), INDEX IDX_8CF786FBDC304035 (salle_id), UNIQUE INDEX uniq_donjon_instance_salle (instance_id, salle_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE donjon_instance_salle ADD CONSTRAINT FK_8CF786FB3A51721D FOREIGN KEY (instance_id) REFERENCES donjon_instance (id)');
        $this->addSql('ALTER TABLE donjon_instance_salle ADD CONSTRAINT FK_8CF786FBDC304035 FOREIGN KEY (salle_id) REFERENCES donjon_salle (id)');
        $this->addSql('ALTER TABLE donjon_salle ADD monstre_id INT DEFAULT NULL, ADD `condition` VARCHAR(32) NOT NULL, ADD condition_params JSON NOT NULL, ADD nombre_monstres INT NOT NULL');
        // Les salles existantes reçoivent une chaîne vide, que l'enum n'accepte pas :
        // sans ce backfill, tout donjon déjà créé devient illisible (ValueError au chargement).
        $this->addSql("UPDATE donjon_salle SET `condition` = 'aucune' WHERE `condition` = ''");
        $this->addSql("UPDATE donjon_salle SET condition_params = '{}' WHERE condition_params IS NULL OR JSON_TYPE(condition_params) = 'NULL'");
        $this->addSql('ALTER TABLE donjon_salle ADD CONSTRAINT FK_32E17AD3DAF13697 FOREIGN KEY (monstre_id) REFERENCES monstre (id)');
        $this->addSql('CREATE INDEX IDX_32E17AD3DAF13697 ON donjon_salle (monstre_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE donjon_instance_salle DROP FOREIGN KEY FK_8CF786FB3A51721D');
        $this->addSql('ALTER TABLE donjon_instance_salle DROP FOREIGN KEY FK_8CF786FBDC304035');
        $this->addSql('DROP TABLE donjon_instance_salle');
        $this->addSql('ALTER TABLE donjon_salle DROP FOREIGN KEY FK_32E17AD3DAF13697');
        $this->addSql('DROP INDEX IDX_32E17AD3DAF13697 ON donjon_salle');
        $this->addSql('ALTER TABLE donjon_salle DROP monstre_id, DROP `condition`, DROP condition_params, DROP nombre_monstres');
    }
}
