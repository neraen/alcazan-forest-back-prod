<?php

namespace App\Exception;

/**
 * Refus métier destiné au JOUEUR (message en français, sorti en 400 par les contrôleurs) :
 * métier non appris, plafond de la famille atteint, métier déjà connu.
 */
class MetierException extends \RuntimeException
{
}
