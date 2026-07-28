<?php

namespace App\Exception;

/**
 * Refus de fabrication destiné au JOUEUR (message en français, sorti en 400 par le
 * contrôleur) : ingrédients manquants, métier ou niveau insuffisant, commande déjà
 * retirée, atelier saturé.
 */
class CraftException extends \RuntimeException
{
}
