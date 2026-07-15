<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Refonte du système de quêtes.
 *
 * Schéma :
 *  - sequence : dialogue inliné (dialogue_titre / dialogue_contenu), suppression
 *    de is_last / has_action / last_sequence / next_sequence (l'ordre = position,
 *    la fin = pas de position suivante) + unique (quete_id, position) ;
 *  - action : type porté par l'enum App\Enum\ActionType (colonne action_type),
 *    api_link/params remplacés par effect/effect_params (whitelist
 *    QuestEffectRegistry — plus d'URL arbitraire en base) ;
 *  - suppression des tables mortes : dialogue, joueur_dialogue, user_sequence,
 *    action_type, action_field, action_field_type ;
 *  - unique user_quete (user_id, quete_id) et recompense (sequence_id).
 *
 * Données : les actions « lien libre » existantes sont retypées en effets
 * scriptés, et toute séquence de quête sans action reçoit un bouton
 * PASSER_DIALOGUE « Terminer » (règle du nouveau moteur : c'est le clic qui
 * fait avancer). Les recompenses à sequence_id NULL sont les récompenses de
 * boss (boss_recompense) : elles sont conservées.
 */
final class Version20260714225015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refonte quêtes : dialogue inliné, actions typées par enum + effets scriptés, tables mortes supprimées';
    }

    public function up(Schema $schema): void
    {
        /* 1. Nouvelles colonnes (action_type nullable le temps du backfill) */
        $this->addSql('ALTER TABLE action ADD action_type INT DEFAULT NULL, ADD effect VARCHAR(64) DEFAULT NULL, ADD effect_params JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE sequence ADD dialogue_titre VARCHAR(255) DEFAULT NULL, ADD dialogue_contenu LONGTEXT DEFAULT NULL');

        /* 2. Backfill des dialogues depuis la table dialogue */
        $this->addSql('UPDATE sequence s JOIN dialogue d ON s.dialogue_id = d.id SET s.dialogue_titre = d.titre, s.dialogue_contenu = d.contenu');

        /* 3. Retypage des actions legacy : liens libres → effets scriptés (ActionType::SCRIPTED_EFFECT = 1) */
        $this->addSql("UPDATE action SET action_type = 1, effect = 'choisir_classe', effect_params = params WHERE api_link = 'user/choice/classe'");
        $this->addSql("UPDATE action SET action_type = 1, effect = 'choisir_alignement', effect_params = params WHERE api_link = 'user/choice/alignement'");
        $this->addSql("UPDATE action SET action_type = 1, effect = 'entrer_auberge' WHERE api_link = 'auberge/entrer'");
        $this->addSql("UPDATE action SET action_type = 1, effect = 'recompense_boss', effect_params = COALESCE(params, '{\"bossId\": 1}') WHERE api_link = 'user/recompense/boss'");
        /* Actions déjà typées par l'ancien référentiel action_type (ids alignés sur l'enum) */
        $this->addSql('UPDATE action SET action_type = action_type_id WHERE action_type IS NULL AND action_type_id IS NOT NULL');

        /* Actions restées sans type (liens libres sans endpoint, ex. guildes/consulter) : suppression */
        $this->addSql('DELETE sa FROM sequence_action sa JOIN action a ON sa.action_id = a.id WHERE a.action_type IS NULL');
        $this->addSql('UPDATE carte_carreau cc JOIN action a ON cc.action_id = a.id SET cc.action_id = NULL WHERE a.action_type IS NULL');
        $this->addSql('DELETE FROM action WHERE action_type IS NULL');

        /* 4. Toute séquence de quête sans action reçoit un bouton PASSER_DIALOGUE (11) « Terminer » */
        /* api_link '' : la colonne, encore NOT NULL à ce stade, est supprimée plus bas */
        $this->addSql("INSERT INTO action (name, api_link, action_type) SELECT CONCAT('__migration_terminer_', s.id), '', 11 FROM sequence s WHERE s.quete_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sequence_action sa WHERE sa.sequence_id = s.id)");
        $this->addSql("INSERT INTO sequence_action (sequence_id, action_id, position) SELECT CAST(SUBSTRING(a.name, 22) AS UNSIGNED), a.id, 1 FROM action a WHERE a.name LIKE '\\_\\_migration\\_terminer\\_%'");
        $this->addSql("UPDATE action SET name = 'Terminer' WHERE name LIKE '\\_\\_migration\\_terminer\\_%'");

        /* 5. Dédoublonnage user_quete avant la contrainte unique (garde la ligne la plus ancienne) */
        $this->addSql('DELETE uq1 FROM user_quete uq1 JOIN user_quete uq2 ON uq1.user_id = uq2.user_id AND uq1.quete_id = uq2.quete_id AND uq1.id > uq2.id');

        /* 6. Suppression des tables mortes et des anciennes colonnes.
           Les FK n'existent que sur les bases antérieures à la baseline
           (la migration initiale crée les tables sans contraintes FK) :
           drops conditionnels pour rester rejouable sur une base neuve. */
        $this->dropForeignKeyIfExists('action', 'FK_47CC8C921FEE0472');
        $this->dropForeignKeyIfExists('sequence', 'FK_5286D72BA6E12CBD');
        $this->dropForeignKeyIfExists('action_field_type', 'FK_FCB054591FEE0472');
        $this->dropForeignKeyIfExists('action_field_type', 'FK_FCB0545993E077D6');
        $this->dropForeignKeyIfExists('joueur_dialogue', 'FK_7B0B32A1A6E12CBD');
        $this->dropForeignKeyIfExists('joueur_dialogue', 'FK_7B0B32A1A9E2D76C');
        $this->dropForeignKeyIfExists('user_sequence', 'FK_B20B332B98FB19AE');
        $this->dropForeignKeyIfExists('user_sequence', 'FK_B20B332BA76ED395');
        $this->addSql('DROP TABLE action_field_type');
        $this->addSql('DROP TABLE action_field');
        $this->addSql('DROP TABLE action_type');
        $this->addSql('DROP TABLE joueur_dialogue');
        $this->addSql('DROP TABLE user_sequence');
        $this->addSql('DROP TABLE dialogue');

        $this->addSql('DROP INDEX IDX_47CC8C921FEE0472 ON action');
        $this->addSql('ALTER TABLE action DROP action_type_id, DROP api_link, DROP params, MODIFY action_type INT NOT NULL');

        $this->dropForeignKeyIfExists('sequence', 'FK_5286D72B9A291D4F');
        $this->dropForeignKeyIfExists('sequence', 'FK_5286D72BD8CD66A2');
        $this->addSql('DROP INDEX IDX_5286D72BA6E12CBD ON sequence');
        $this->addSql('DROP INDEX IDX_5286D72BD8CD66A2 ON sequence');
        $this->addSql('DROP INDEX IDX_5286D72B9A291D4F ON sequence');
        $this->addSql('ALTER TABLE sequence DROP dialogue_id, DROP last_sequence_id, DROP next_sequence_id, DROP is_last, DROP has_action');

        /* 7. Contraintes d'intégrité du nouveau modèle */
        $this->addSql('ALTER TABLE recompense DROP INDEX IDX_1E9BC0DE98FB19AE, ADD UNIQUE INDEX UNIQ_1E9BC0DE98FB19AE (sequence_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sequence_quete_position ON sequence (quete_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_quete ON user_quete (user_id, quete_id)');
    }

    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        $exists = (int)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $constraint]
        );
        if ($exists > 0) {
            $this->addSql("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'La refonte du système de quêtes transforme les données (dialogues inlinés, actions retypées) : restaurer un dump (backups/) pour revenir en arrière.'
        );
    }
}
