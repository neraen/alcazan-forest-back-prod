<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guildes réelles : `joueur_guilde` devient la seule vérité, `user.guilde_id` disparaît.
 *
 * ## Le bug corrigé
 *
 * `user.guilde_id` et `joueur_guilde` coexistaient. L'adhésion écrivait dans la seconde,
 * TOUT l'affichage lisait la première, et **aucun code n'écrivait jamais la première** :
 * rejoindre une guilde n'avait donc strictement aucun effet visible.
 *
 * ⚠️ La colonne n'était pas morte pour autant — elle portait des données saisies à la main
 * et QUATRE jointures la lisaient (`UserRepository` ×2, `CarteCarreauRepository`,
 * `DonjonInstanceMembreRepository`). Les jointures sont récrites dans le même lot, AVANT
 * cette migration ; la supprimer sans cela effacerait le nom de guilde sur le profil, sur la
 * carte et dans la liste des membres d'instance de donjon.
 *
 * ## L'ORDRE des opérations ci-dessous n'est pas cosmétique
 *
 * Le diff généré par Doctrine posait l'index UNIQUE avant tout dédoublonnage et supprimait
 * `user.guilde_id` avant d'en remonter le contenu — deux façons de perdre des données sur une
 * base existante. La séquence est donc : **colonnes tolérantes → normalisation → remontée →
 * dédoublonnage → contraintes → suppressions**. Chaque étape suppose la précédente.
 *
 * ## Trois détails qui se paient cher si on les rate
 *
 * 1. **`candidate_le` est ajoutée NULLABLE puis passée NOT NULL** : une colonne NOT NULL sans
 *    défaut casse sur des lignes existantes (piège déjà payé, cf. `CLAUDE.md`).
 * 2. **La casse des grades est normalisée** : la base contenait `'Baron'` et `'Recrue'`
 *    capitalisés, l'enum `GradeGuilde` attend des minuscules. Sans ce `LOWER()`, toute
 *    lecture d'une ligne existante lèverait une erreur d'hydratation d'enum. Une valeur
 *    inconnue retombe sur `recrue` plutôt que de faire échouer le chargement.
 * 3. **Les lignes existantes deviennent MEMBRES** : l'ancien endpoint prétendait créer une
 *    « candidature » mais rien ne permettait de l'accepter — ces joueurs étaient de fait dans
 *    la guilde, et les rétrograder en candidats les en sortirait.
 *
 * `grade` et `joueur_grade` sont supprimées : zéro ligne, aucune permission portée, remplacées
 * par l'enum `GradeGuilde`. ⚠️ `grade` étant dans le seed de contenu, ce lot impose un
 * `./scripts/content-dump.sh --push`, faute de quoi `content-load.sh` la recréerait ailleurs.
 */
final class Version20260801200221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guildes : joueur_guilde devient la seule vérité (statut, grades typés), suppression de user.guilde_id et des tables grade';
    }

    public function up(Schema $schema): void
    {
        // 1. Colonnes neuves, TOLÉRANTES : `candidate_le` est nullable le temps du backfill.
        $this->addSql("ALTER TABLE joueur_guilde
            ADD statut VARCHAR(20) DEFAULT 'membre' NOT NULL,
            ADD rejoint_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD candidate_le DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        // 2. Normalisation de la casse des grades, puis repli sur `recrue` pour l'inconnu.
        $this->addSql('UPDATE joueur_guilde SET grade = LOWER(grade)');
        $this->addSql("UPDATE joueur_guilde SET grade = 'recrue'
            WHERE grade NOT IN ('baron', 'officier', 'membre', 'recrue')");

        // 3. Les appartenances existantes sont des adhésions effectives.
        $this->addSql("UPDATE joueur_guilde
            SET statut = 'membre',
                rejoint_le = COALESCE(rejoint_le, NOW()),
                candidate_le = COALESCE(candidate_le, NOW())");

        // 4. Remontée de `user.guilde_id` — AVANT toute suppression. Le `NOT EXISTS` évite de
        //    doubler un joueur qui a déjà sa ligne, et `joueur_guilde` fait alors foi.
        $this->addSql("INSERT INTO joueur_guilde (user_id, guilde_id, grade, statut, rejoint_le, candidate_le)
            SELECT u.id, u.guilde_id, 'baron', 'membre', NOW(), NOW()
            FROM user u
            WHERE u.guilde_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM joueur_guilde jg WHERE jg.user_id = u.id)");

        // 5. Dédoublonnage AVANT l'index UNIQUE : l'ancien endpoint n'avait aucun garde-fou,
        //    des doublons sont donc possibles et feraient échouer la contrainte.
        $this->addSql('DELETE doublon FROM joueur_guilde doublon
            JOIN (SELECT user_id, MIN(id) AS garder FROM joueur_guilde GROUP BY user_id) garde
              ON doublon.user_id = garde.user_id AND doublon.id > garde.garder');

        // 6. Contraintes, une fois les données saines.
        $this->addSql("ALTER TABLE joueur_guilde
            MODIFY candidate_le DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            MODIFY grade VARCHAR(20) NOT NULL");
        $this->addSql('ALTER TABLE joueur_guilde DROP INDEX IDX_471050CBA76ED395, ADD UNIQUE INDEX uniq_joueur_guilde_user (user_id)');
        $this->addSql('ALTER TABLE joueur_guilde DROP FOREIGN KEY FK_471050CBA2E96BBE');
        $this->addSql('ALTER TABLE joueur_guilde DROP FOREIGN KEY FK_471050CBA76ED395');
        $this->addSql('ALTER TABLE joueur_guilde ADD CONSTRAINT FK_471050CBA2E96BBE FOREIGN KEY (guilde_id) REFERENCES guilde (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE joueur_guilde ADD CONSTRAINT FK_471050CBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        // 7. SEULEMENT MAINTENANT : la colonne dupliquée disparaît.
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649A2E96BBE');
        $this->addSql('DROP INDEX IDX_8D93D649A2E96BBE ON user');
        $this->addSql('ALTER TABLE user DROP guilde_id');

        // 8. Tables mortes (zéro ligne), remplacées par l'enum GradeGuilde.
        $this->addSql('ALTER TABLE joueur_grade DROP FOREIGN KEY FK_81EF1732A76ED395');
        $this->addSql('ALTER TABLE joueur_grade DROP FOREIGN KEY FK_81EF1732FE19A1A8');
        $this->addSql('DROP TABLE joueur_grade');
        $this->addSql('DROP TABLE grade');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE grade (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, icone VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE joueur_grade (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, grade_id INT DEFAULT NULL, INDEX IDX_81EF1732A76ED395 (user_id), INDEX IDX_81EF1732FE19A1A8 (grade_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE joueur_grade ADD CONSTRAINT FK_81EF1732A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE joueur_grade ADD CONSTRAINT FK_81EF1732FE19A1A8 FOREIGN KEY (grade_id) REFERENCES grade (id)');

        $this->addSql('ALTER TABLE user ADD guilde_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649A2E96BBE FOREIGN KEY (guilde_id) REFERENCES guilde (id)');
        $this->addSql('CREATE INDEX IDX_8D93D649A2E96BBE ON user (guilde_id)');
        // Restitution de la colonne depuis la source qui l'a remplacée.
        $this->addSql("UPDATE user u
            JOIN joueur_guilde jg ON jg.user_id = u.id AND jg.statut = 'membre'
            SET u.guilde_id = jg.guilde_id");

        $this->addSql('ALTER TABLE joueur_guilde DROP FOREIGN KEY FK_471050CBA76ED395');
        $this->addSql('ALTER TABLE joueur_guilde DROP FOREIGN KEY FK_471050CBA2E96BBE');
        $this->addSql('ALTER TABLE joueur_guilde DROP INDEX uniq_joueur_guilde_user, ADD INDEX IDX_471050CBA76ED395 (user_id)');
        $this->addSql('ALTER TABLE joueur_guilde DROP statut, DROP rejoint_le, DROP candidate_le, CHANGE grade grade VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE joueur_guilde ADD CONSTRAINT FK_471050CBA2E96BBE FOREIGN KEY (guilde_id) REFERENCES guilde (id)');
        $this->addSql('ALTER TABLE joueur_guilde ADD CONSTRAINT FK_471050CBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }
}
