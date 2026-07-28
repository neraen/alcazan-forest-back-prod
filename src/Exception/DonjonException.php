<?php

namespace App\Exception;

/**
 * Erreur du domaine donjons dont le message (en français) peut être renvoyé
 * tel quel au joueur (réponse 400) : verrou déjà consommé, instance pleine,
 * niveau insuffisant…
 */
class DonjonException extends \RuntimeException
{
}
