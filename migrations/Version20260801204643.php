<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user.honneur` devient NOT NULL DEFAULT 0.
 *
 * La colonne était nullable depuis l'origine, et `$user->getHonneur() + $gain` opérait donc
 * sur NULL sans prévenir — le premier duel d'un compte neuf partait d'une valeur qui n'était
 * pas zéro mais « rien ».
 *
 * ⚠️ L'UPDATE précède l'ALTER, et cet ordre n'est pas cosmétique : passer une colonne à
 * NOT NULL alors que des lignes valent NULL échoue sur données existantes (même famille de
 * piège que « colonne NOT NULL sans défaut », cf. `CLAUDE.md`).
 *
 * Muté désormais UNIQUEMENT par `HonneurService`, qui borne la valeur — l'honneur était la
 * seule valeur de progression du jeu sans point de mutation unique.
 */
final class Version20260801204643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.honneur passe NOT NULL DEFAULT 0 (point de mutation unique : HonneurService)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // D'ABORD les données, ENSUITE la contrainte.
        $this->addSql('UPDATE user SET honneur = 0 WHERE honneur IS NULL');
        $this->addSql('ALTER TABLE user CHANGE honneur honneur INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user CHANGE honneur honneur INT DEFAULT NULL');
    }
}
