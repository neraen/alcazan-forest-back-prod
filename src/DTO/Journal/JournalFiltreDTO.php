<?php

namespace App\DTO\Journal;

use App\Config\JournalConfig;
use App\Enum\CategorieEvenement;
use App\Enum\TypeEvenement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filtres du flux d'administration. Tout est optionnel : ouvrir le journal sans rien
 * préciser doit marcher et montrer les derniers événements.
 *
 * `types` et `categorie` sont validés contre les enums plutôt que passés tels quels : ces
 * valeurs finissent dans un `WHERE ... IN`, ce n'est pas la place d'une chaîne venue du
 * client. Une valeur inconnue est ignorée silencieusement plutôt que rejetée — un filtre
 * périmé côté écran ne doit pas transformer une consultation en erreur 400.
 */
class JournalFiltreDTO
{
    public function __construct(
        #[Assert\Positive(message: "L'identifiant de joueur est invalide.")]
        public readonly ?int $userId = null,
        /** @var list<string>|null */
        public readonly ?array $types = null,
        public readonly ?string $categorie = null,
        public readonly ?string $depuis = null,
        public readonly ?string $jusqua = null,
        #[Assert\Positive(message: "Le numéro de page est invalide.")]
        public readonly ?int $page = null,
        #[Assert\Positive(message: "La taille de page est invalide.")]
        public readonly ?int $parPage = null,
    ) {}

    /**
     * Les types demandés, ou tous ceux de la catégorie si c'est elle qui est filtrée.
     *
     * La catégorie n'existe pas en base (elle est dérivée du type) : la filtrer revient
     * donc à élargir la liste des types. C'est ce qui permet à la catégorie de rester une
     * vue et non une colonne à maintenir.
     *
     * @return list<TypeEvenement>|null null = aucun filtre
     */
    public function types(): ?array
    {
        if ($this->types !== null && $this->types !== []) {
            $types = array_values(array_filter(array_map(
                static fn ($valeur) => is_string($valeur) ? TypeEvenement::tryFrom($valeur) : null,
                $this->types
            )));

            return $types === [] ? null : $types;
        }

        $categorie = $this->categorie === null ? null : CategorieEvenement::tryFrom($this->categorie);
        if ($categorie === null) {
            return null;
        }

        return array_values(array_filter(
            TypeEvenement::cases(),
            static fn (TypeEvenement $type) => $type->categorie() === $categorie
        ));
    }

    public function depuis(): ?\DateTimeImmutable
    {
        return self::date($this->depuis);
    }

    public function jusqua(): ?\DateTimeImmutable
    {
        return self::date($this->jusqua);
    }

    public function page(): int
    {
        return max(1, $this->page ?? 1);
    }

    public function parPage(): int
    {
        return min(JournalConfig::PAGE_MAX, max(1, $this->parPage ?? JournalConfig::PAGE_PAR_DEFAUT));
    }

    /** Une date illisible vaut « pas de filtre », jamais une erreur. */
    private static function date(?string $valeur): ?\DateTimeImmutable
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }
    }
}
