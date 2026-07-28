<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724223740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Donjon : traçage du ramassage du butin de boss (un coffre par mise à mort).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_boss ADD last_loot DATETIME DEFAULT NULL');
        // Les kills antérieurs n'ont jamais rien distribué (recompense_boss ne faisait
        // qu'afficher un message) : on considère leur butin comme déjà ramassé pour ne
        // pas offrir un lot rétroactif à tous les joueurs.
        $this->addSql('UPDATE user_boss SET last_loot = last_kill');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_boss DROP last_loot');
    }
}
