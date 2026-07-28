<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Karma des choix de quête et compteurs de progression.
 *
 * `joueur_compteur` est une table de RUNTIME joueur (liste noire de content-dump.sh).
 * Son index UNIQUE (user, type, cible) n'est pas seulement une garde d'intégrité :
 * c'est lui qui rend possible l'upsert `ON DUPLICATE KEY UPDATE` du repository, seul
 * moyen de ne pas perdre d'incrément entre deux requêtes concurrentes. La cible est un
 * entier nu, sans FK : le type dit déjà vers quelle table elle pointe.
 *
 * `action.karma` est nullable et SIGNÉ — un choix peut coûter de la réputation.
 * `user_quete.compteurs_depart` mémorise l'état des compteurs à l'entrée dans l'étape ;
 * NULL = lecture cumulative, ce qui est exactement le comportement voulu pour les
 * quêtes déjà en cours au moment de la migration.
 */
final class Version20260726211924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quêtes : karma par choix, compteurs de progression (joueur_compteur), cible recette';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE joueur_compteur (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(32) NOT NULL, cible_id INT NOT NULL, valeur INT DEFAULT 0 NOT NULL, maj_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_C783354DA76ED395 (user_id), UNIQUE INDEX uniq_joueur_compteur (user_id, type, cible_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE joueur_compteur ADD CONSTRAINT FK_C783354DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE action ADD recette_id INT DEFAULT NULL, ADD karma INT DEFAULT NULL');
        $this->addSql('ALTER TABLE action ADD CONSTRAINT FK_47CC8C9289312FE9 FOREIGN KEY (recette_id) REFERENCES recette (id)');
        $this->addSql('CREATE INDEX IDX_47CC8C9289312FE9 ON action (recette_id)');
        $this->addSql('ALTER TABLE user_quete ADD compteurs_depart JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE joueur_compteur DROP FOREIGN KEY FK_C783354DA76ED395');
        $this->addSql('DROP TABLE joueur_compteur');
        $this->addSql('ALTER TABLE action DROP FOREIGN KEY FK_47CC8C9289312FE9');
        $this->addSql('DROP INDEX IDX_47CC8C9289312FE9 ON action');
        $this->addSql('ALTER TABLE action DROP recette_id, DROP karma');
        $this->addSql('ALTER TABLE user_quete DROP compteurs_depart');
    }
}
