<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quêtes à embranchement :
 *  - action.next_sequence_id / ends_quest : branchement d'un choix vers une
 *    autre séquence (ou fin de quête) au lieu du linéaire position + 1 ;
 *  - la récompense passe de la séquence à l'action (une récompense par
 *    branche/choix). La récompense d'une séquence est clonée sur CHACUNE de
 *    ses actions pour préserver le comportement existant (ex. quête de choix
 *    de classe : chaque « Devenir X » donne la même récompense qu'avant).
 *
 * Les récompenses de boss (recompense.sequence_id NULL, liées via
 * boss_recompense) ne sont pas touchées.
 */
final class Version20260721213359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quêtes à embranchement : action.next_sequence_id/ends_quest + récompense par action (déplacée depuis la séquence)';
    }

    public function up(Schema $schema): void
    {
        // Branchement porté par l'action.
        $this->addSql('ALTER TABLE action ADD next_sequence_id INT DEFAULT NULL, ADD ends_quest TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE action ADD CONSTRAINT FK_47CC8C929A291D4F FOREIGN KEY (next_sequence_id) REFERENCES sequence (id)');
        $this->addSql('CREATE INDEX IDX_47CC8C929A291D4F ON action (next_sequence_id)');

        // Nouvelle cible de la récompense : l'action.
        $this->addSql('ALTER TABLE recompense ADD action_id INT DEFAULT NULL');

        // Migration des données : une récompense par action de la séquence.
        $this->addSql(<<<'SQL'
            INSERT INTO recompense (action_id, objet_id, equipement_id, consommable_id, money, experience, quantity)
            SELECT sa.action_id, r.objet_id, r.equipement_id, r.consommable_id, r.money, r.experience, r.quantity
            FROM recompense r
            JOIN sequence_action sa ON sa.sequence_id = r.sequence_id
            WHERE r.sequence_id IS NOT NULL
        SQL);
        $this->addSql('DELETE FROM recompense WHERE sequence_id IS NOT NULL');

        // Suppression de l'ancien lien séquence. La contrainte FK n'existe que
        // sur la base live (dérive de schéma) : la chaîne de migrations crée
        // recompense.sequence_id avec un simple index, sans FK. On ne la drop
        // donc que si elle existe, pour rester rejouable sur une base vierge.
        if ($this->foreignKeyExists('recompense', 'FK_1E9BC0DE98FB19AE')) {
            $this->addSql('ALTER TABLE recompense DROP FOREIGN KEY FK_1E9BC0DE98FB19AE');
        }
        $this->addSql('DROP INDEX UNIQ_1E9BC0DE98FB19AE ON recompense');
        $this->addSql('ALTER TABLE recompense DROP sequence_id');

        // Contrainte sur la nouvelle colonne.
        $this->addSql('ALTER TABLE recompense ADD CONSTRAINT FK_1E9BC0DE9D32F035 FOREIGN KEY (action_id) REFERENCES action (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1E9BC0DE9D32F035 ON recompense (action_id)');
    }

    public function down(Schema $schema): void
    {
        // Branchement.
        $this->addSql('ALTER TABLE action DROP FOREIGN KEY FK_47CC8C929A291D4F');
        $this->addSql('DROP INDEX IDX_47CC8C929A291D4F ON action');
        $this->addSql('ALTER TABLE action DROP next_sequence_id, DROP ends_quest');

        // Ré-attache la récompense à la séquence (best-effort : une seule par
        // séquence, on garde la récompense d'id le plus faible et on jette les
        // clones surnuméraires issus de la migration up()).
        $this->addSql('ALTER TABLE recompense ADD sequence_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE recompense r
            JOIN sequence_action sa ON sa.action_id = r.action_id
            SET r.sequence_id = sa.sequence_id
            WHERE r.action_id IS NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            DELETE r1 FROM recompense r1
            JOIN recompense r2
              ON r1.sequence_id = r2.sequence_id
             AND r1.sequence_id IS NOT NULL
             AND r1.id > r2.id
        SQL);

        $this->addSql('ALTER TABLE recompense DROP FOREIGN KEY FK_1E9BC0DE9D32F035');
        $this->addSql('DROP INDEX UNIQ_1E9BC0DE9D32F035 ON recompense');
        $this->addSql('ALTER TABLE recompense DROP action_id');

        $this->addSql('ALTER TABLE recompense ADD CONSTRAINT FK_1E9BC0DE98FB19AE FOREIGN KEY (sequence_id) REFERENCES sequence (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1E9BC0DE98FB19AE ON recompense (sequence_id)');
    }

    /** Une contrainte de clé étrangère existe-t-elle sur la table courante ? */
    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return (bool)$this->connection->fetchOne(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ?",
            [$table, $constraint]
        );
    }
}
