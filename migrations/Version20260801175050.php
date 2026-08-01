<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index des classements assis sur un état courant du joueur.
 *
 * Deux des cinq catégories (richesse, honneur) ne sont PAS des cumuls : elles trient
 * directement `user`, parce que `user.money` et `user.honneur` sont des états courants et
 * que les recopier dans `joueur_cumul` créerait une seconde vérité sur l'or. Sans index,
 * chaque consultation du classement ferait donc un tri de table complète.
 *
 * `hors_classement` est en TÊTE des deux index, et ce n'est pas cosmétique : la requête est
 * `WHERE hors_classement = 0 ORDER BY money DESC`. Avec la colonne filtrante en tête, MySQL
 * parcourt l'index déjà trié ; avec `(money, hors_classement)`, il devrait filtrer après
 * coup et retomberait sur un tri.
 *
 * Les classements de cumul, eux, sont déjà servis par `idx_joueur_cumul_classement (cle,
 * valeur)` posé avec la table.
 */
final class Version20260801175050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index des classements par richesse et par honneur';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_user_classement_richesse ON user (hors_classement, money)');
        $this->addSql('CREATE INDEX idx_user_classement_honneur ON user (hors_classement, honneur)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_user_classement_richesse ON user');
        $this->addSql('DROP INDEX idx_user_classement_honneur ON user');
    }
}
