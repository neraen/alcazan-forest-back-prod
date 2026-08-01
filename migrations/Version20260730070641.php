<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hôtel des ventes : marché asynchrone entre joueurs.
 *
 * `hotel_vente` est une table de RUNTIME joueur (liste noire de content-dump.sh) : elle est
 * alimentée par les joueurs, elle bouge en permanence et elle n'a rien à faire dans le seed
 * de contenu partagé entre machines.
 *
 * `item_id` n'a volontairement PAS de clé étrangère : le jeu n'a pas d'instance d'objet, et
 * la colonne pointe vers `equipement`, `consommable` ou `objet` selon `type` — trois FK
 * nullables mutuellement exclusives coûteraient plus qu'elles ne garantissent. Même choix
 * qu'`echange_ligne`. Corollaire assumé : un item supprimé du contenu laisse une annonce
 * orpheline, que le normalizer sait décrire (« Objet inconnu ») et que son vendeur peut
 * encore retirer.
 *
 * Trois index de service plutôt qu'un seul : le catalogue filtre sur (statut, type, item_id),
 * l'onglet « Mes ventes » sur (vendeur, statut), et la commande d'expiration balaie
 * (statut, expires_at) toutes les minutes.
 *
 * Pas de colonne `version` contrairement à `echange` : une annonce n'est pas co-éditée, seul
 * son statut bascule, et la course entre deux acheteurs se règle par verrou pessimiste.
 *
 * Aucune donnée préexistante à reprendre : la table est créée vide, donc les colonnes
 * DATETIME NOT NULL ne posent pas le problème de mode strict rencontré à l'artisanat.
 */
final class Version20260730070641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Hôtel des ventes : table hotel_vente (runtime joueur, marché asynchrone)";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hotel_vente (id INT AUTO_INCREMENT NOT NULL, vendeur_id INT NOT NULL, acheteur_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, item_id INT NOT NULL, quantite INT NOT NULL, prix INT NOT NULL, frais_depot INT NOT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_6B5EE488858C065E (vendeur_id), INDEX IDX_6B5EE48896A7BB5F (acheteur_id), INDEX idx_hotel_vente_catalogue (statut, type, item_id), INDEX idx_hotel_vente_vendeur (vendeur_id, statut), INDEX idx_hotel_vente_expiration (statut, expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE hotel_vente ADD CONSTRAINT FK_6B5EE488858C065E FOREIGN KEY (vendeur_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE hotel_vente ADD CONSTRAINT FK_6B5EE48896A7BB5F FOREIGN KEY (acheteur_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hotel_vente DROP FOREIGN KEY FK_6B5EE488858C065E');
        $this->addSql('ALTER TABLE hotel_vente DROP FOREIGN KEY FK_6B5EE48896A7BB5F');
        $this->addSql('DROP TABLE hotel_vente');
    }
}
