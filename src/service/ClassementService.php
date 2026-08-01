<?php

namespace App\service;

use App\Config\ClassementConfig;
use App\Entity\User;
use App\Enum\CategorieClassement;
use App\Repository\JoueurCumulRepository;
use App\Repository\JoueurGuildeRepository;
use App\Repository\UserRepository;

/**
 * Point d'entrée UNIQUE des classements publics.
 *
 * « Unique » a ici un sens précis et une conséquence : toutes les lectures de classement
 * passent par `top()` et `rangDe()`, ce qui rend le choix « calcul à la volée » RÉVERSIBLE.
 * Le jour où la volumétrie ne le permet plus, matérialiser revient à créer une table de
 * snapshot, écrire une commande de scheduler, et changer le corps de ces deux méthodes —
 * sans toucher au contrôleur ni au front. Voir `ClassementConfig` pour le seuil.
 *
 * Le service ne connaît pas la différence entre un cumul et un état courant : c'est
 * `CategorieClassement` qui la porte, et c'est elle qui dit où lire. Ça permet d'ajouter un
 * classement sans toucher ici.
 */
class ClassementService
{
    public function __construct(
        private readonly JoueurCumulRepository $cumulRepository,
        private readonly UserRepository $userRepository,
        private readonly JoueurGuildeRepository $joueurGuildeRepository,
    ) {}

    /**
     * Les catégories proposées, dans l'ordre d'affichage.
     *
     * Le front n'en connaît aucune en dur : libellé, intitulé de colonne et format viennent
     * d'ici. Ajouter un classement reste une modification back seulement.
     *
     * @return list<array{valeur: string, label: string, intitule: string, format: string}>
     */
    public function categories(): array
    {
        return array_map(
            static fn (CategorieClassement $categorie) => [
                'valeur' => $categorie->value,
                'label' => $categorie->label(),
                'intitule' => $categorie->intitule(),
                'format' => $categorie->format(),
                'cible' => $categorie->cible(),
            ],
            CategorieClassement::cases()
        );
    }

    /**
     * Le haut du classement, rang déjà calculé.
     *
     * Le rang est posé ici plutôt que par le client : deux joueurs à égalité partagent le
     * même rang, ce qu'un simple `index + 1` côté front ne saurait pas faire.
     *
     * @return list<array{rang: int, userId: int, pseudo: string, niveau: ?int, classe: ?string, valeur: int}>
     */
    public function top(CategorieClassement $categorie, ?int $limite = null): array
    {
        $limite = $limite ?? ClassementConfig::TAILLE_TOP;

        // Le classement des guildes agrège plusieurs joueurs par ligne : il ne peut pas
        // passer par les mêmes requêtes, mais il sort la MÊME forme de ligne (`pseudo` porte
        // le nom de la guilde), pour que le tableau du front reste unique.
        if ($categorie === CategorieClassement::GUILDES) {
            $lignes = array_map(
                static fn (array $ligne) => [
                    'userId' => $ligne['guildeId'],
                    'pseudo' => $ligne['nom'],
                    'niveau' => null,
                    'classe' => null,
                    'membres' => $ligne['membres'],
                    'valeur' => $ligne['valeur'],
                ],
                $this->joueurGuildeRepository->classementParCumul($categorie->cumul()->value, $limite)
            );
        } else {
            $cumul = $categorie->cumul();
            $lignes = $cumul !== null
                ? $this->cumulRepository->top($cumul, $limite)
                : $this->userRepository->topParEtat($categorie->colonneUser(), $limite);
        }

        $rang = 0;
        $position = 0;
        $valeurPrecedente = null;

        foreach ($lignes as $index => $ligne) {
            ++$position;
            if ($ligne['valeur'] !== $valeurPrecedente) {
                $rang = $position;
                $valeurPrecedente = $ligne['valeur'];
            }
            $lignes[$index]['rang'] = $rang;
        }

        return $lignes;
    }

    /**
     * Le rang du joueur pour une catégorie, même s'il est hors du top.
     *
     * C'est ce qui rend la pagination inutile : un joueur classé 312ᵉ n'a pas besoin de
     * parcourir six pages pour le savoir.
     *
     * Un joueur exclu des classements (`hors_classement`) reçoit `null` : il n'a pas de rang,
     * et lui en afficher un serait mentir puisqu'il n'apparaît nulle part dans la liste.
     *
     * @return array{rang: int, valeur: int}|null
     */
    public function rangDe(User $user, CategorieClassement $categorie): ?array
    {
        // Une guilde n'a pas de « rang personnel » : le joueur n'est pas la ligne classée.
        if ($user->isHorsClassement() || $categorie === CategorieClassement::GUILDES) {
            return null;
        }

        $cumul = $categorie->cumul();

        return $cumul !== null
            ? $this->cumulRepository->rang((int) $user->getId(), $cumul)
            : $this->userRepository->rangParEtat((int) $user->getId(), $categorie->colonneUser());
    }
}
