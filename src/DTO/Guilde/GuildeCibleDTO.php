<?php

namespace App\DTO\Guilde;

use Symfony\Component\Validator\Constraints as Assert;

/** Un joueur visé par une décision de guilde (accepter, refuser, exclure, promouvoir). */
class GuildeCibleDTO
{
    public function __construct(
        #[Assert\NotBlank(message: "Le champ userId est obligatoire.")]
        #[Assert\Positive(message: "Le champ userId est invalide.")]
        public readonly ?int $userId = null,
        /**
         * Grade demandé, pour la promotion seule.
         *
         * Chaîne et non enum : un champ typé enum répond 500 et non 422 sur une valeur
         * inconnue (`BackedEnumNormalizer`, cf. doc §21.8). Le contrôleur résout par
         * `tryFrom()` et refuse proprement.
         */
        public readonly ?string $grade = null,
    ) {}
}
