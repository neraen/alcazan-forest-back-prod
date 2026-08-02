<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Présence : `user.derniere_activite`, source de la pastille « en ligne » de l'étiquette de
 * survol d'un joueur sur la carte.
 *
 * ## Pourquoi une colonne, et pas `last_connexion`
 *
 * `last_connexion` est la dernière OUVERTURE DE SESSION — elle répond à « qui a joué cette
 * semaine », ce que le tableau de bord d'administration lui demande depuis le lot 4. Elle est
 * structurellement incapable de répondre à « qui est là maintenant » : un joueur en pleine
 * partie depuis six heures a une `last_connexion` vieille de six heures. Deux questions,
 * deux colonnes ; les fusionner ferait perdre la première pour servir mal la seconde.
 *
 * ## Le backfill
 *
 * `derniere_activite = last_connexion` plutôt que NULL. C'est une approximation, mais dans le
 * bon sens : un joueur authentifié il y a deux minutes qui n'a pas encore bougé serait affiché
 * hors ligne à froid, jusqu'à sa première requête. Personne n'est déclaré présent à tort —
 * `last_connexion` est toujours antérieure ou égale à l'activité réelle.
 *
 * **Aucun index.** La colonne n'apparaît que dans un `CASE WHEN` de projection
 * (`CarteCarreauRepository::getAllCasesOfMap`), jamais dans un `WHERE` ni un `ORDER BY` : un
 * index ne servirait à rien et coûterait une écriture supplémentaire par minute et par joueur.
 */
final class Version20260802073958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Présence des joueurs : user.derniere_activite (pastille en ligne / hors ligne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD derniere_activite DATETIME DEFAULT NULL');
        $this->addSql('UPDATE user SET derniere_activite = last_connexion WHERE last_connexion IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP derniere_activite');
    }
}
