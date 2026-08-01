<?php

namespace App\DTO\Classement;

use App\Enum\CategorieClassement;

/**
 * La catégorie demandée.
 *
 * Le champ est une CHAÎNE et non directement l'enum, alors que le projet type volontiers ses
 * DTO avec des enums (`HotelVenteCatalogueDTO`). La raison est concrète : quand une valeur
 * inconnue arrive sur un champ typé enum, `BackedEnumNormalizer` lève une
 * `InvalidArgumentException` que `#[MapRequestPayload]` ne convertit pas — le client reçoit
 * une **500** au lieu d'une erreur de requête. On résout donc à la main via `tryFrom()`, ce
 * qui rend l'enum tout aussi gardienne de la valeur (elle finit dans un `ORDER BY`) mais
 * permet de répondre proprement.
 *
 * ⚠️ Le travers est GÉNÉRAL au projet, pas propre à ce fichier : `POST /api/hotel/catalogue`
 * avec un `type` inconnu répond 500 pour exactement la même raison. À traiter globalement
 * (normalizer d'enum tolérant ou écouteur d'exception), pas ici.
 */
class ClassementCategorieDTO
{
    public function __construct(
        public readonly ?string $categorie = null,
    ) {}

    /**
     * La catégorie résolue, ou null si la valeur est inconnue.
     *
     * Absente = la première catégorie : ouvrir la page sans rien préciser doit marcher.
     * Inconnue = null, et c'est au contrôleur de refuser — les deux cas ne se confondent pas.
     */
    public function resoudre(): ?CategorieClassement
    {
        if ($this->categorie === null || trim($this->categorie) === '') {
            return CategorieClassement::cases()[0];
        }

        return CategorieClassement::tryFrom($this->categorie);
    }
}
