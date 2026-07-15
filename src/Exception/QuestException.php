<?php

namespace App\Exception;

/**
 * Erreur du domaine quêtes dont le message (en français) peut être
 * renvoyé tel quel au joueur ou à l'admin (réponse 400).
 */
class QuestException extends \RuntimeException
{
}
