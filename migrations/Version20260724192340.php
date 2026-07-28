<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Socle du système d'échange joueur-à-joueur :
 * - table reservation_ressource (quantités d'items/or mises de côté, lues par SacService :
 *   disponible = possédé - réservé) ;
 * - index uniques manquants sur inventaire_consommable / inventaire_objet (même garde-fou
 *   anti-duplication que uniq_inventaire_equipement posé le 23/07). Les éventuelles lignes en
 *   doublon sont fusionnées (somme des quantités) avant la pose de l'index.
 */
final class Version20260724192340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réservations de ressources + index uniques inventaire_consommable/inventaire_objet';
    }

    public function up(Schema $schema): void
    {
        // Fusion des doublons éventuels : la ligne conservée (id min) récupère la somme.
        foreach ([['inventaire_consommable', 'consommable_id'], ['inventaire_objet', 'objet_id']] as [$table, $colonne]) {
            $this->addSql(sprintf(
                'UPDATE %1$s ligne JOIN (SELECT MIN(id) keep_id, inventaire_id, %2$s, SUM(quantity) total FROM %1$s GROUP BY inventaire_id, %2$s HAVING COUNT(*) > 1) doublon ON ligne.id = doublon.keep_id SET ligne.quantity = doublon.total',
                $table,
                $colonne
            ));
            $this->addSql(sprintf(
                'DELETE ligne FROM %1$s ligne JOIN (SELECT MIN(id) keep_id, inventaire_id, %2$s FROM %1$s GROUP BY inventaire_id, %2$s HAVING COUNT(*) > 1) doublon ON ligne.inventaire_id = doublon.inventaire_id AND ligne.%2$s = doublon.%2$s AND ligne.id <> doublon.keep_id',
                $table,
                $colonne
            ));
        }

        $this->addSql('CREATE TABLE reservation_ressource (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(20) NOT NULL, item_id INT NOT NULL, quantite INT NOT NULL, origine VARCHAR(32) NOT NULL, origine_id INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_89D824DBA76ED395 (user_id), INDEX idx_reservation_user_ressource (user_id, type, item_id), UNIQUE INDEX uniq_reservation_ressource (user_id, type, item_id, origine, origine_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reservation_ressource ADD CONSTRAINT FK_89D824DBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inventaire_consommable ON inventaire_consommable (inventaire_id, consommable_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inventaire_objet ON inventaire_objet (inventaire_id, objet_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation_ressource DROP FOREIGN KEY FK_89D824DBA76ED395');
        $this->addSql('DROP TABLE reservation_ressource');
        $this->addSql('DROP INDEX uniq_inventaire_consommable ON inventaire_consommable');
        $this->addSql('DROP INDEX uniq_inventaire_objet ON inventaire_objet');
    }
}
