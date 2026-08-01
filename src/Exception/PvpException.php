<?php

namespace App\Exception;

/**
 * Refus d'une action PvP. Le message est destiné au JOUEUR — il doit dire ce qui bloque
 * (« hors de portée », « pas assez de PA »), pas ce qui a planté.
 *
 * ⚠️ **Un refus doit se voir** : c'est la leçon déjà payée sur les passages de donjon
 * (`CLAUDE.md`), où les toasts commentés faisaient qu'un clic ne produisait RIEN de visible
 * — ce qui se lit comme un bug du jeu et non comme une règle.
 */
class PvpException extends \DomainException
{
}
