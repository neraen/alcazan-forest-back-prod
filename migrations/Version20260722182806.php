<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722182806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ShopMaker : prix par ligne (shop_equipement/shop_objet) + section consommable (shop_consommable)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shop_consommable (id INT AUTO_INCREMENT NOT NULL, shop_id INT NOT NULL, consommable_id INT NOT NULL, prix INT DEFAULT NULL, INDEX IDX_723B895D4D16C4DD (shop_id), INDEX IDX_723B895DC9CEB381 (consommable_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE shop_consommable ADD CONSTRAINT FK_723B895D4D16C4DD FOREIGN KEY (shop_id) REFERENCES shop (id)');
        $this->addSql('ALTER TABLE shop_consommable ADD CONSTRAINT FK_723B895DC9CEB381 FOREIGN KEY (consommable_id) REFERENCES consommable (id)');
        $this->addSql('ALTER TABLE shop_equipement ADD prix INT DEFAULT NULL');
        $this->addSql('ALTER TABLE shop_objet ADD prix INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shop_consommable DROP FOREIGN KEY FK_723B895D4D16C4DD');
        $this->addSql('ALTER TABLE shop_consommable DROP FOREIGN KEY FK_723B895DC9CEB381');
        $this->addSql('DROP TABLE shop_consommable');
        $this->addSql('ALTER TABLE shop_equipement DROP prix');
        $this->addSql('ALTER TABLE shop_objet DROP prix');
    }
}
