<?php

namespace App\Exception;

/**
 * Pas refusé par le serveur. Le message, en français, peut être renvoyé tel quel au
 * joueur (réponse 400) : case trop loin, infranchissable, occupée par un PNJ, plus de PM.
 */
class DeplacementException extends \RuntimeException
{
}
