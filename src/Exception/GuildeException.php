<?php

namespace App\Exception;

/**
 * Refus d'une transition de guilde. Le message est destiné au JOUEUR : il doit dire ce qui
 * bloque, pas ce qui a planté — même contrat que `MetierException` et `CraftException`.
 */
class GuildeException extends \DomainException
{
}
