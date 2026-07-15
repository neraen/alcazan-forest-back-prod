<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713070356 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nettoyage : suppression de rarete (doublon de rarity) et guilde.nb_joueur_max (doublon de place_max) ; réalignement du référentiel action_type sur App\Enum\ActionType';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE rarete');
        $this->addSql('ALTER TABLE guilde DROP nb_joueur_max');

        // Réalignement des ids d'action_type sur App\Enum\ActionType : les anciens ids
        // ne correspondaient pas aux valeurs de l'enum utilisées par QuestControlleur
        // et QuestService, rendant le QuestMaker incohérent.
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('DELETE FROM action_field_type');
        $this->addSql('DELETE FROM action_type');
        $this->addSql("INSERT INTO action_type (id, name, is_recursive) VALUES
            (1,'json',0),(2,'donnerObjet',0),(3,'donnerOr',0),(4,'donnerEquipement',0),
            (5,'donnerConsommable',0),(6,'atteindreLevel',0),(7,'parlerPnj',0),(8,'battreBoss',0),
            (9,'battreMonstre',0),(10,'choix',0),(11,'passerDialogue',0),(12,'possederObjet',0),
            (13,'visiterCarte',0),(14,'killPvp',0)");
        $this->addSql('INSERT INTO action_field_type (action_field_id, action_type_id) VALUES
            (1,2),(3,2),(1,3),(2,4),(1,4),(4,5),(1,5),(1,6),(11,7),(5,8),(10,9),(1,9),(12,13),(3,12),(1,12)');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE rarete (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, couleur VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE guilde ADD nb_joueur_max INT NOT NULL');
    }
}
