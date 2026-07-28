<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artisanat, lot 3 : recettes et fabrication.
 *
 * `recette` + `recette_ingredient` sont du CONTENU (capturés par le seed).
 * `craft_commande` est du RUNTIME : elle a été ajoutée à la liste noire de
 * `scripts/content-dump.sh` — sans quoi les fabrications en cours des joueurs partiraient
 * dans le seed partagé.
 */
final class Version20260726121919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artisanat lot 3 : recette, recette_ingredient, craft_commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE craft_commande (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, recette_id INT NOT NULL, mode VARCHAR(16) NOT NULL, statut VARCHAR(16) NOT NULL, lancee_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', pret_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', retiree_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ingredients JSON NOT NULL, INDEX IDX_A9B8B4B7A76ED395 (user_id), INDEX IDX_A9B8B4B789312FE9 (recette_id), INDEX idx_craft_commande_user (user_id, statut), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recette (id INT AUTO_INCREMENT NOT NULL, metier_id INT NOT NULL, recompense_id INT DEFAULT NULL, nom VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, niveau_requis INT NOT NULL, difficulte INT NOT NULL, temps_secondes INT NOT NULL, experience_metier INT NOT NULL, actif TINYINT(1) NOT NULL, INDEX IDX_49BB6390ED16FA20 (metier_id), INDEX IDX_49BB63904D714096 (recompense_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recette_ingredient (id INT AUTO_INCREMENT NOT NULL, recette_id INT NOT NULL, objet_id INT DEFAULT NULL, equipement_id INT DEFAULT NULL, consommable_id INT DEFAULT NULL, quantite INT NOT NULL, INDEX IDX_17C041A9F520CF5A (objet_id), INDEX IDX_17C041A9806F0F5C (equipement_id), INDEX IDX_17C041A9C9CEB381 (consommable_id), INDEX idx_recette_ingredient (recette_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE craft_commande ADD CONSTRAINT FK_A9B8B4B7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE craft_commande ADD CONSTRAINT FK_A9B8B4B789312FE9 FOREIGN KEY (recette_id) REFERENCES recette (id)');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB6390ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('ALTER TABLE recette ADD CONSTRAINT FK_49BB63904D714096 FOREIGN KEY (recompense_id) REFERENCES recompense (id)');
        $this->addSql('ALTER TABLE recette_ingredient ADD CONSTRAINT FK_17C041A989312FE9 FOREIGN KEY (recette_id) REFERENCES recette (id)');
        $this->addSql('ALTER TABLE recette_ingredient ADD CONSTRAINT FK_17C041A9F520CF5A FOREIGN KEY (objet_id) REFERENCES objet (id)');
        $this->addSql('ALTER TABLE recette_ingredient ADD CONSTRAINT FK_17C041A9806F0F5C FOREIGN KEY (equipement_id) REFERENCES equipement (id)');
        $this->addSql('ALTER TABLE recette_ingredient ADD CONSTRAINT FK_17C041A9C9CEB381 FOREIGN KEY (consommable_id) REFERENCES consommable (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE craft_commande DROP FOREIGN KEY FK_A9B8B4B7A76ED395');
        $this->addSql('ALTER TABLE craft_commande DROP FOREIGN KEY FK_A9B8B4B789312FE9');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB6390ED16FA20');
        $this->addSql('ALTER TABLE recette DROP FOREIGN KEY FK_49BB63904D714096');
        $this->addSql('ALTER TABLE recette_ingredient DROP FOREIGN KEY FK_17C041A989312FE9');
        $this->addSql('ALTER TABLE recette_ingredient DROP FOREIGN KEY FK_17C041A9F520CF5A');
        $this->addSql('ALTER TABLE recette_ingredient DROP FOREIGN KEY FK_17C041A9806F0F5C');
        $this->addSql('ALTER TABLE recette_ingredient DROP FOREIGN KEY FK_17C041A9C9CEB381');
        $this->addSql('DROP TABLE craft_commande');
        $this->addSql('DROP TABLE recette');
        $this->addSql('DROP TABLE recette_ingredient');
    }
}
