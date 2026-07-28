<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725092350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Donjons : mécaniques de combat (menace, phases, zones, renforts, enrage, leviers).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE donjon_instance_levier (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, actionne_par_id INT NOT NULL, carte_carreau_id INT NOT NULL, actionne_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_788540113A51721D (instance_id), INDEX IDX_78854011316F6B16 (actionne_par_id), UNIQUE INDEX uniq_donjon_levier (instance_id, carte_carreau_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_instance_monstre (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, monstre_id INT NOT NULL, carte_id INT NOT NULL, abscisse INT NOT NULL, ordonnee INT NOT NULL, current_life INT NOT NULL, vivant TINYINT(1) NOT NULL, apparu_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F02E55C53A51721D (instance_id), INDEX IDX_F02E55C5DAF13697 (monstre_id), INDEX idx_donjon_monstre_instance (instance_id, vivant), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_instance_zone (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, carte_id INT NOT NULL, cases JSON NOT NULL, degats INT NOT NULL, annoncee_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', resoudre_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', resolue TINYINT(1) NOT NULL, annonce VARCHAR(255) DEFAULT NULL, INDEX IDX_17ECC0BB3A51721D (instance_id), INDEX idx_donjon_zone_instance (instance_id, resolue), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE donjon_mecanique (id INT AUTO_INCREMENT NOT NULL, donjon_id INT NOT NULL, type VARCHAR(32) NOT NULL, vie_max INT NOT NULL, vie_min INT NOT NULL, cooldown_secondes INT NOT NULL, params JSON NOT NULL, ordre INT NOT NULL, actif TINYINT(1) NOT NULL, annonce VARCHAR(255) DEFAULT NULL, INDEX idx_donjon_mecanique_donjon (donjon_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE donjon_instance_levier ADD CONSTRAINT FK_788540113A51721D FOREIGN KEY (instance_id) REFERENCES donjon_instance (id)');
        $this->addSql('ALTER TABLE donjon_instance_levier ADD CONSTRAINT FK_78854011316F6B16 FOREIGN KEY (actionne_par_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE donjon_instance_monstre ADD CONSTRAINT FK_F02E55C53A51721D FOREIGN KEY (instance_id) REFERENCES donjon_instance (id)');
        $this->addSql('ALTER TABLE donjon_instance_monstre ADD CONSTRAINT FK_F02E55C5DAF13697 FOREIGN KEY (monstre_id) REFERENCES monstre (id)');
        $this->addSql('ALTER TABLE donjon_instance_zone ADD CONSTRAINT FK_17ECC0BB3A51721D FOREIGN KEY (instance_id) REFERENCES donjon_instance (id)');
        $this->addSql('ALTER TABLE donjon_mecanique ADD CONSTRAINT FK_62B2D075C530111B FOREIGN KEY (donjon_id) REFERENCES donjon (id)');
        $this->addSql('ALTER TABLE donjon_instance ADD combat_debut_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD dernier_tick_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD mecaniques_jouees JSON NOT NULL');
        // Colonne JSON NOT NULL ajoutée après coup : les instances existantes reçoivent
        // NULL, que la propriété typée `array` refuse (TypeError à l'hydratation).
        // MySQL remplit une colonne JSON NOT NULL ajoutée après coup avec le LITTÉRAL JSON
        // `null` (et non un NULL SQL) : Doctrine le décode en null, que la propriété typée
        // `array` refuse. Il faut donc tester JSON_TYPE, pas IS NULL.
        $this->addSql("UPDATE donjon_instance SET mecaniques_jouees = '{}'
                       WHERE mecaniques_jouees IS NULL OR JSON_TYPE(mecaniques_jouees) = 'NULL'");
        $this->addSql('ALTER TABLE donjon_instance_membre ADD menace INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE donjon_instance_levier DROP FOREIGN KEY FK_788540113A51721D');
        $this->addSql('ALTER TABLE donjon_instance_levier DROP FOREIGN KEY FK_78854011316F6B16');
        $this->addSql('ALTER TABLE donjon_instance_monstre DROP FOREIGN KEY FK_F02E55C53A51721D');
        $this->addSql('ALTER TABLE donjon_instance_monstre DROP FOREIGN KEY FK_F02E55C5DAF13697');
        $this->addSql('ALTER TABLE donjon_instance_zone DROP FOREIGN KEY FK_17ECC0BB3A51721D');
        $this->addSql('ALTER TABLE donjon_mecanique DROP FOREIGN KEY FK_62B2D075C530111B');
        $this->addSql('DROP TABLE donjon_instance_levier');
        $this->addSql('DROP TABLE donjon_instance_monstre');
        $this->addSql('DROP TABLE donjon_instance_zone');
        $this->addSql('DROP TABLE donjon_mecanique');
        $this->addSql('ALTER TABLE donjon_instance DROP combat_debut_at, DROP dernier_tick_at, DROP mecaniques_jouees');
        $this->addSql('ALTER TABLE donjon_instance_membre DROP menace');
    }
}
