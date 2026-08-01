<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal d'événements de jeu (`evenement_jeu`) — socle du monitoring et des statistiques.
 *
 * Table de RUNTIME joueur : elle est ajoutée à la liste noire de `content-dump.sh` dans le
 * même lot. L'oublier ferait fuiter tout l'historique de partie dans le seed de contenu
 * partagé par git.
 *
 * ## Les choix qui ne se devinent pas à la lecture du SQL
 *
 * **`id` en BIGINT.** C'est la seule table du projet dont la croissance n'est pas bornée par
 * le contenu. Le plafond d'un INT n'est pas atteignable ici, mais élargir une clé primaire
 * auto-incrémentée après coup est une migration bloquante sur une grosse table, alors que
 * l'anticipation coûte quatre octets par ligne.
 *
 * **`montant_or` et non `or`.** `OR` est un mot réservé MySQL. Même famille de piège que
 * `donjon_salle.condition`, à ceci près qu'on l'évite ici par le nommage plutôt que par des
 * backticks : un nom de colonne qui doit être échappé finit toujours par casser un INSERT
 * quelque part.
 *
 * **`cible_id` sans clé étrangère.** La colonne est polymorphe — `cible_type` dit vers
 * quelle table elle pointe — exactement comme `joueur_compteur.cible_id`, `echange_ligne.item_id`
 * et `hotel_vente.item_id`. Trois FK nullables mutuellement exclusives coûteraient plus
 * qu'elles ne garantissent. Contrepartie assumée : un contenu supprimé laisse un événement
 * dont la cible ne se résout plus, et `JournalNormalizer` affiche « Monstre inconnu (#12) ».
 * C'est acceptable pour une table d'archive ; ça ne le serait pas pour du gameplay.
 *
 * **`cible_user_id` est une COLONNE, pas une clé du contexte JSON.** La requête n°1 de
 * l'administration est « la fiche du joueur X », c'est-à-dire tout ce qu'il a fait ET subi.
 * En JSON, cette requête deviendrait un scan complet avec JSON_EXTRACT sur précisément la
 * table qu'on ne peut pas scanner ; en colonne indexée, c'est deux parcours d'index.
 *
 * **Les deux FK vers `user` n'ont PAS de `ON DELETE CASCADE`**, contrairement à
 * `joueur_compteur`. Un compte ne se supprime pas dans ce jeu ; si ça arrivait un jour, on
 * veut que la suppression échoue bruyamment plutôt qu'efface en silence l'historique
 * d'enquête qui expliquait pourquoi on supprimait le compte.
 *
 * ## Les quatre index, et pourquoi aucun ne peut être fusionné
 *
 *  - `(acteur_id, cree_le)` : fiche joueur, derniers actes. Préfixe `acteur_id` obligatoire.
 *  - `(cible_user_id, cree_le)` : « ce qu'il a subi ». Autre colonne de tête, donc autre index.
 *  - `(type, cree_le)` : flux admin filtré par type, agrégats par jour. Idem.
 *  - `(cree_le)` : balayage de la purge et flux global non filtré — un index composite ne
 *    sert pas un `WHERE cree_le < ?` seul.
 *
 * Pas d'index sur `cible_id` : aucune requête du périmètre ne demande « tous les événements
 * sur le monstre 12 ». Il coûterait 100 % des écritures pour 0 % des lectures, et cette
 * question-là se répond de toute façon mieux via `joueur_compteur`.
 *
 * Les deux index simples `IDX_…(acteur_id)` et `IDX_…(cible_user_id)` sont générés par
 * Doctrine pour ses `ManyToOne` ; ils font doublon avec le préfixe gauche des index
 * composites ci-dessus. On les garde tels quels : les retirer à la main ferait revenir la
 * différence au prochain `doctrine:migrations:diff`.
 */
final class Version20260801110033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal d\'événements de jeu (evenement_jeu) : socle du monitoring admin et des statistiques joueur';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evenement_jeu (id BIGINT AUTO_INCREMENT NOT NULL, acteur_id INT DEFAULT NULL, cible_user_id INT DEFAULT NULL, type VARCHAR(40) NOT NULL, cible_type VARCHAR(20) DEFAULT NULL, cible_id INT DEFAULT NULL, quantite INT DEFAULT 0 NOT NULL, montant_or INT DEFAULT 0 NOT NULL, contexte JSON DEFAULT NULL, cree_le DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_6D8174D7DA6F574A (acteur_id), INDEX IDX_6D8174D76A2544E6 (cible_user_id), INDEX idx_evenement_acteur (acteur_id, cree_le), INDEX idx_evenement_cible_user (cible_user_id, cree_le), INDEX idx_evenement_type (type, cree_le), INDEX idx_evenement_date (cree_le), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE evenement_jeu ADD CONSTRAINT FK_6D8174D7DA6F574A FOREIGN KEY (acteur_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE evenement_jeu ADD CONSTRAINT FK_6D8174D76A2544E6 FOREIGN KEY (cible_user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evenement_jeu DROP FOREIGN KEY FK_6D8174D7DA6F574A');
        $this->addSql('ALTER TABLE evenement_jeu DROP FOREIGN KEY FK_6D8174D76A2544E6');
        $this->addSql('DROP TABLE evenement_jeu');
    }
}
