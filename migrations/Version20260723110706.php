<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garde-fous en base contre les doublons d'objets : une seule ligne de pile par couple
 * sac/objet, et un même objet non porté deux fois par le même joueur. Filet de sécurité sous
 * EquipementEquipeService, après le bug d'échange qui dupliquait l'objet équipé (23/07/2026).
 */
final class Version20260723110706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index uniques sur inventaire_equipement et user_equipement (anti-duplication)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_inventaire_equipement ON inventaire_equipement (inventaire_id, equipement_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_equipement ON user_equipement (user_id, equipement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_inventaire_equipement ON inventaire_equipement');
        $this->addSql('DROP INDEX uniq_user_equipement ON user_equipement');
    }
}
