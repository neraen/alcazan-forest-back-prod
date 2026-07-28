<?php

namespace App\Exception;

/**
 * Erreur du domaine des cases interactives dont le message (en français) peut être
 * renvoyé tel quel au joueur (réponse 400) : trop loin, métier insuffisant, pas assez
 * de PA, ressource encore en recharge.
 */
class InteractionException extends \RuntimeException
{
}
