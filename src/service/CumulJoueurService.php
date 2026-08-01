<?php

namespace App\service;

use App\Entity\User;
use App\Enum\TypeCumul;
use App\Repository\JoueurCumulRepository;

/**
 * UNIQUE point de mutation des cumuls de partie (`joueur_cumul`).
 * Ne flushe pas et n'ouvre pas de transaction : l'appelant fournit la transaction, même
 * contrat que CompteurJoueurService, SacService, RecompenseService, MetierService et
 * KarmaService.
 *
 * Un cumul répond à « combien au total ce joueur a-t-il fait ça ? », sans cible. Il est
 * **cumulatif et jamais remis à zéro** : c'est un fait de partie. La pièce voisine,
 * `joueur_compteur`, répond à la question par cible, et les quêtes ne lisent QU'ELLE — un
 * cumul n'a pas de sens pour « tuez 5 loups ». Voir `TypeCumul` pour la raison pour laquelle
 * les deux ne pouvaient pas tenir dans la même table.
 *
 * Deux cumuls sont des DÉNORMALISATIONS assumées (`MONSTRES_TUES` depuis `joueur_compteur`,
 * `BOSS_VAINCUS` depuis `user_boss`). Ce qui les rend légitimes est qu'elles sont
 * recalculables : `app:cumuls:reparer` les refait depuis leurs sources, et un test asserte
 * la concordance. La règle : *une dénormalisation n'est acceptable que si elle est
 * reconstructible depuis sa source.*
 */
class CumulJoueurService
{
    public function __construct(private readonly JoueurCumulRepository $repository) {}

    /**
     * Ajoute `$pas` et renvoie la valeur atteinte.
     * Un pas nul ou négatif est ignoré : un cumul ne redescend jamais.
     */
    public function ajouter(User $user, TypeCumul $cle, int $pas = 1): int
    {
        return $this->ajouterParId((int)$user->getId(), $cle, $pas);
    }

    /**
     * Variante par identifiant.
     *
     * Elle existe pour `LevelingService::giveExperienceToAPlayer`, qui reçoit un `int $userId`
     * et pas un `User` : charger l'entité juste pour lire son id serait un aller-retour base
     * gratuit sur un chemin appelé à chaque coup porté du jeu. L'écriture étant un INSERT
     * natif, l'identifiant suffit.
     */
    public function ajouterParId(int $userId, TypeCumul $cle, int $pas = 1): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if ($pas <= 0) {
            return $this->repository->valeurParId($userId, $cle);
        }

        return $this->repository->ajouterParId($userId, $cle, $pas);
    }

    /** Valeur d'un cumul (0 s'il n'a jamais été alimenté). */
    public function valeur(User $user, TypeCumul $cle): int
    {
        return $this->repository->valeurParId((int)$user->getId(), $cle);
    }

    /**
     * Tous les cumuls d'un joueur, en UNE requête, prêts pour l'affichage.
     *
     * Les libellés et le format viennent d'ici et non du front : le client ne doit connaître
     * aucune clé en dur, même discipline que `TypeCompteur::unite()`. Les clés jamais
     * alimentées sont rendues à 0 plutôt qu'omises — une fiche de personnage neuf doit
     * afficher « 0 monstre vaincu », pas une ligne manquante.
     *
     * @param list<TypeCumul>|null $cles null = tous
     * @return list<array{cle: string, label: string, unite: string, format: string, valeur: int}>
     */
    public function decrire(User $user, ?array $cles = null): array
    {
        $valeurs = $this->repository->valeurs($user);

        return array_map(
            static fn (TypeCumul $cle) => [
                'cle' => $cle->value,
                'label' => $cle->label(),
                'unite' => $cle->unite(),
                'format' => $cle->format(),
                'valeur' => $valeurs[$cle->value] ?? 0,
            ],
            $cles ?? TypeCumul::cases()
        );
    }
}
