<?php

namespace App\Exception;

use App\Enum\ActionType;

/**
 * Un type d'action réservé (BATTRE_MONSTRE, CHOIX, KILL_PVP) a été rencontré :
 * la vérification serveur n'existe pas encore, on refuse bruyamment plutôt
 * que de laisser passer silencieusement (ancien default => true).
 */
class UnsupportedQuestActionException extends QuestException
{
    public function __construct(ActionType $type)
    {
        parent::__construct("Le type d'action {$type->name} n'est pas encore supporté.");
    }
}
