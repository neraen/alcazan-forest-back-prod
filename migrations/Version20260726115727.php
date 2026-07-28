<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artisanat, lot 2 : `interaction.recolte_choix` — cette case propose-t-elle le choix
 * entre récolte mesurée et récolte intensive ?
 */
final class Version20260726115727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artisanat lot 2 : interaction.recolte_choix';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT 0 : toutes les cases déjà posées gardent exactement leur comportement,
        // et l'ajout passe en mode strict même si la table est peuplée.
        $this->addSql('ALTER TABLE interaction ADD recolte_choix TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE interaction DROP recolte_choix');
    }
}
