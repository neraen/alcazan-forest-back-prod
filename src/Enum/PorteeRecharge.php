<?php

namespace App\Enum;

/**
 * À QUI s'applique le délai de rechargement d'une interaction. C'est la clé de voûte du
 * système : une seule mécanique de cooldown couvre des besoins très différents selon
 * sa portée.
 */
enum PorteeRecharge: string
{
    /** Chacun son cooldown : une herbe que tout le monde peut récolter de son côté. */
    case JOUEUR = 'joueur';

    /**
     * Un seul cooldown pour tout le serveur : le premier qui ouvre le coffre le vide
     * pour les autres jusqu'à la recharge. C'est la portée des coffres de monde.
     */
    case MONDE = 'monde';

    /**
     * Par expédition de donjon : chaque groupe a son propre état. Sans cette portée, un
     * groupe ouvrirait le coffre d'un autre — même défaut que `monstre_carreau`.
     */
    case INSTANCE = 'instance';

    public function label(): string
    {
        return match ($this) {
            self::JOUEUR => 'Par joueur',
            self::MONDE => 'Partagée (tout le serveur)',
            self::INSTANCE => "Par expédition de donjon",
        };
    }
}
