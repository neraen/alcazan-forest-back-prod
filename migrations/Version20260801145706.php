<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cumuls de partie (`joueur_cumul`) et exclusion des classements.
 *
 * Table de RUNTIME joueur : elle est ajoutée à la liste noire de `content-dump.sh` dans le
 * même lot.
 *
 * ## Pourquoi une table et pas un `TypeCompteur` de plus
 *
 * `joueur_compteur` répond à « combien de fois, PAR CIBLE » ; ces totaux-là n'ont pas de
 * cible. Et on ne pouvait pas leur en inventer une : `CompteurJoueurService::incrementer`
 * **refuse `$cibleId <= 0`**, il n'existe donc aucune « cible 0 » disponible. Forcer une
 * fausse cible aurait cassé l'invariant que `CLAUDE.md` décrit comme la clé de voûte des
 * compteurs.
 *
 * ## Les index
 *
 * L'index UNIQUE `(user_id, cle)` n'est pas seulement une garde d'intégrité : c'est lui qui
 * rend possible l'upsert `ON DUPLICATE KEY UPDATE` du repository, seul moyen de ne pas
 * perdre d'incrément entre deux requêtes concurrentes. Le retirer ferait silencieusement
 * sous-compter.
 *
 * `(cle, valeur)` sert TOUS les classements de cumul avec un seul index : un
 * `WHERE cle = ? ORDER BY valeur DESC LIMIT n` devient un parcours d'index borné, et le rang
 * personnel (`COUNT(*) WHERE cle = ? AND valeur > ?`) est couvert par le même.
 *
 * `valeur` est un BIGINT : l'XP totale d'un personnage de haut niveau se compte en centaines
 * de millions, et élargir une colonne indexée après coup est une migration bloquante.
 *
 * ## `user.hors_classement`
 *
 * Une colonne booléenne plutôt qu'un test sur `roles` : filtrer avec `JSON_CONTAINS` sur
 * chaque lecture détruirait l'index qui sert justement à trier. Elle est mise à 1 pour les
 * comptes ROLE_ADMIN existants — sans quoi le compte de développement, rempli de données de
 * test, trusterait tous les podiums le jour du déploiement.
 *
 * ## Les backfills : deux exacts, un approché
 *
 * `MONSTRES_TUES` et `BOSS_VAINCUS` sont reconstruits EXACTEMENT depuis leurs sources
 * (`joueur_compteur` et `user_boss`) — ce sont des dénormalisations, et c'est précisément ce
 * qui les rend légitimes.
 *
 * ⚠️ `XP_TOTALE`, lui, est une **BORNE INFÉRIEURE** et le restera. On reconstruit « somme des
 * paliers franchis + XP courante dans le niveau », ce qui ignore toute l'XP réellement gagnée
 * puis reperdue : `LevelingService::giveExpMalusAfterDeath` retire 9 % du palier à chaque
 * mort. Un vétéran mort souvent est donc sous-estimé.
 *
 * On le fait quand même plutôt que de partir de zéro, et c'est un arbitrage assumé : à zéro,
 * un personnage de niveau 49 serait classé DERRIÈRE un nouveau venu qui tue un loup le
 * lendemain du déploiement. Un classement visiblement faux le premier jour ne se rattrape
 * pas ; une borne inférieure documentée, si.
 *
 * Les joueurs sans ligne `niveau_joueur` (comptes incomplets) n'ont simplement pas de ligne :
 * l'absence vaut 0 à la lecture, c'est la dégradation voulue.
 */
final class Version20260801145706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cumuls de partie (joueur_cumul), exclusion des classements, et backfill des totaux existants';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE joueur_cumul (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, cle VARCHAR(40) NOT NULL, valeur BIGINT DEFAULT 0 NOT NULL, maj_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CAA7EB9BA76ED395 (user_id), INDEX idx_joueur_cumul_classement (cle, valeur), UNIQUE INDEX uniq_joueur_cumul (user_id, cle), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE joueur_cumul ADD CONSTRAINT FK_CAA7EB9BA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD hors_classement TINYINT(1) DEFAULT 0 NOT NULL');

        // Les comptes d'administration existants sortent des classements.
        $this->addSql("UPDATE user SET hors_classement = 1 WHERE JSON_CONTAINS(roles, '\"ROLE_ADMIN\"')");

        // Backfill EXACT : monstres vaincus, depuis les compteurs par cible.
        $this->addSql("
            INSERT INTO joueur_cumul (user_id, cle, valeur, maj_at)
            SELECT user_id, 'monstres_tues', SUM(valeur), NOW()
            FROM joueur_compteur
            WHERE type = 'monstre_tue'
            GROUP BY user_id
        ");

        // Backfill EXACT : boss vaincus, depuis user_boss (qui reste la source de vérité).
        $this->addSql("
            INSERT INTO joueur_cumul (user_id, cle, valeur, maj_at)
            SELECT user_id, 'boss_vaincus', SUM(number_kill), NOW()
            FROM user_boss
            GROUP BY user_id
        ");

        // Backfill APPROCHÉ (borne inférieure, cf. docblock) : XP totale.
        $this->addSql("
            INSERT INTO joueur_cumul (user_id, cle, valeur, maj_at)
            SELECT nj.user_id,
                   'xp_totale',
                   COALESCE((SELECT SUM(n2.experience) FROM niveau n2 WHERE n2.niveau < n.niveau), 0) + nj.experience,
                   NOW()
            FROM niveau_joueur nj
            JOIN niveau n ON n.id = nj.niveau_id
            WHERE nj.user_id IS NOT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE joueur_cumul DROP FOREIGN KEY FK_CAA7EB9BA76ED395');
        $this->addSql('DROP TABLE joueur_cumul');
        $this->addSql('ALTER TABLE user DROP hors_classement');
    }
}
