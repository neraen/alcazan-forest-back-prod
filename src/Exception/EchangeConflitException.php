<?php

namespace App\Exception;

/**
 * La version envoyée par le client ne correspond plus à l'état de la session d'échange
 * (l'autre joueur a agi entre-temps). Portée en HTTP 409 par EchangeController, avec
 * l'état frais normalisé pour que le front se resynchronise sans requête supplémentaire.
 */
class EchangeConflitException extends \RuntimeException
{
    public function __construct(
        private readonly array $etat,
        string $message = "L'état de l'échange a changé."
    ) {
        parent::__construct($message);
    }

    public function getEtat(): array
    {
        return $this->etat;
    }
}
