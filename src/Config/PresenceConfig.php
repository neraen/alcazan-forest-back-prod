<?php

namespace App\Config;

/**
 * Curseurs de la présence « en ligne / hors ligne » — pendant de `PvpConfig`, `JournalConfig`
 * et consorts : aucun chiffre en dur ailleurs, front compris (la fenêtre n'est jamais
 * recalculée côté client, le serveur descend un booléen déjà tranché).
 *
 * ## Pourquoi une colonne de plus, et pas `user.last_connexion`
 *
 * `last_connexion` est la dernière OUVERTURE DE SESSION. Un joueur qui joue depuis six heures
 * a une `last_connexion` vieille de six heures : elle sait dire « qui a joué cette semaine »
 * (c'est ce que le tableau de bord lui demande) mais elle est structurellement incapable de
 * dire « qui est là maintenant ». Deux questions, deux colonnes.
 *
 * ## Les deux curseurs ne sont pas le même
 *
 * `RAFRAICHISSEMENT_SECONDES` est un curseur de COÛT : il borne le nombre d'UPDATE que la
 * présence provoque (au pire un par joueur et par minute, quel que soit le nombre de requêtes
 * — et le jeu en envoie plusieurs par déplacement).
 *
 * `FENETRE_EN_LIGNE_MINUTES` est un curseur de SENS : au-delà, on cesse d'affirmer qu'un
 * joueur est là. Il doit rester nettement supérieur au premier, sinon un joueur bien présent
 * clignoterait entre les deux états au gré du décalage entre son dernier UPDATE et la lecture.
 */
final class PresenceConfig
{
    /** Au-delà, un joueur est déclaré hors ligne. */
    public const FENETRE_EN_LIGNE_MINUTES = 5;

    /** Délai minimal entre deux écritures de `derniere_activite` pour un même joueur. */
    public const RAFRAICHISSEMENT_SECONDES = 60;

    /** Le seuil à comparer à `user.derniere_activite` pour trancher « en ligne ». */
    public static function seuilEnLigne(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('-' . self::FENETRE_EN_LIGNE_MINUTES . ' minutes');
    }

    /** Cette activité est-elle assez fraîche pour qu'une nouvelle écriture soit inutile ? */
    public static function estAJour(?\DateTimeInterface $derniereActivite): bool
    {
        return $derniereActivite !== null
            && $derniereActivite->getTimestamp() > time() - self::RAFRAICHISSEMENT_SECONDES;
    }
}
