<?php

namespace App\service;

use App\Entity\User;
use App\Enum\TypeCible;
use App\Enum\TypeEvenement;
use App\Enum\TypeItem;
use App\Repository\EvenementJeuRepository;
use Psr\Log\LoggerInterface;

/**
 * UNIQUE point d'écriture du journal d'événements (`evenement_jeu`).
 *
 * ## Ce que le journal est, et ce qu'il n'est pas
 *
 * Il répond à « QU'EST-CE QUI S'EST PASSÉ ». Il ne répond pas à « combien ce joueur
 * possède-t-il » : cette vérité-là est l'inventaire, dont `SacService` est déjà l'unique
 * point de mutation. Le journal n'est donc PAS un grand livre comptable, et il peut
 * manquer un mouvement d'or sans que ce soit un défaut — confondre les deux, c'est se
 * condamner à réconcilier deux sources qui divergeront.
 *
 * Corollaire pratique : on consigne UNE LIGNE PAR FAIT, jamais une par mutation. Un
 * échange conclu est un fait ; c'est aussi six à dix appels à `SacService`. C'est la
 * raison pour laquelle le journal s'écrit chez les APPELANTS — qui connaissent la cause —
 * et jamais au centre dans `SacService`, qui n'en a aucune notion.
 *
 * ## Pourquoi un INSERT natif et pas `persist()`
 *
 * 1. Un `persist` en attente serait écrit par le flush de l'appelant, donc à un moment que
 *    ce service ne contrôle pas — et éventuellement jamais si l'appelant ne flushe pas.
 * 2. `DeathService::diePlayer` écrit en DQL hors unité de travail et finit par un
 *    `entityManager->refresh($user)` obligatoire. Une entité en attente dans l'UoW à cet
 *    instant est un piège de premier ordre.
 * 3. Un INSERT natif est insensible à `clear()`, `refresh()` et aux erreurs de mapping.
 *
 * ## « Hors unité de travail » ≠ « hors transaction »
 *
 * L'INSERT emprunte la MÊME connexion DBAL : il PARTICIPE donc à la transaction ouverte
 * par l'appelant. C'est délibéré, et c'est le cœur de l'arbitrage :
 *
 *  - un rollback annule l'action ET le log → **souhaitable** : un journal qui garde la
 *    trace d'un échange annulé mènerait l'enquête sur une fausse piste, ce qui est pire
 *    que l'absence de trace ;
 *  - l'action réussit mais le log échoue (JSON trop long, colonne trop courte) → le
 *    `catch` avale, Monolog enregistre, le jeu continue. **On perd une ligne de journal,
 *    jamais une action de jeu.**
 *  - le log échoue sur un verrou → la transaction est de toute façon condamnée par InnoDB
 *    et l'erreur ressortira au commit ; le `catch` ne masque rien.
 *
 * L'invariant, en une phrase : *le journal ne doit jamais faire échouer une action, et ne
 * doit jamais mentir sur une action qui n'a pas eu lieu.* Les deux se règlent avec la même
 * décision — même transaction, exceptions avalées.
 *
 * Écarté : bufferiser en mémoire pour écrire après le commit. Cette variante survit au
 * rollback, c'est-à-dire qu'elle journalise des faits qui n'ont pas eu lieu.
 */
class JournalService
{
    public function __construct(
        private readonly EvenementJeuRepository $repository,
        private readonly SacService $sacService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Consigne un fait. N'échoue jamais du point de vue de l'appelant.
     *
     * @param array $contexte librement structuré ; conventions reconnues par
     *                        `TypeEvenement::phrase()` : `items` (liste figée par
     *                        `figerItems()`), `cause` (mort), `fraisDepot` (hôtel des ventes)
     */
    public function consigner(
        TypeEvenement $type,
        ?User $acteur = null,
        ?User $cibleUser = null,
        ?TypeCible $cibleType = null,
        ?int $cibleId = null,
        int $quantite = 0,
        int $montantOr = 0,
        array $contexte = [],
    ): void {
        try {
            $this->repository->inserer(
                $this->construireLigne($type, $acteur, $cibleUser, $cibleType, $cibleId, $quantite, $montantOr, $contexte)
            );
        } catch (\Throwable $erreur) {
            $this->signaler($erreur, $type);
        }
    }

    /**
     * Consigne plusieurs faits en un seul INSERT. Destiné aux commandes de lot.
     *
     * @param list<array{type: TypeEvenement, acteur?: ?User, cibleUser?: ?User,
     *                   cibleType?: ?TypeCible, cibleId?: ?int, quantite?: int,
     *                   montantOr?: int, contexte?: array}> $evenements
     */
    public function consignerPlusieurs(array $evenements): void
    {
        if ($evenements === []) {
            return;
        }

        try {
            $lignes = [];
            foreach ($evenements as $evenement) {
                $lignes[] = $this->construireLigne(
                    $evenement['type'],
                    $evenement['acteur'] ?? null,
                    $evenement['cibleUser'] ?? null,
                    $evenement['cibleType'] ?? null,
                    $evenement['cibleId'] ?? null,
                    $evenement['quantite'] ?? 0,
                    $evenement['montantOr'] ?? 0,
                    $evenement['contexte'] ?? [],
                );
            }

            $this->repository->insererPlusieurs($lignes);
        } catch (\Throwable $erreur) {
            $this->signaler($erreur, $evenements[0]['type'] ?? null);
        }
    }

    /**
     * Fige le nom des items au moment de l'événement, pour `contexte.items`.
     *
     * C'est OBLIGATOIRE et non un confort : `echange_ligne.item_id` et `hotel_vente.item_id`
     * n'ont pas de clé étrangère (choix documenté §20), donc aucune requête SQL ne pourra
     * jamais joindre le nom a posteriori. Bénéfice collatéral : l'événement reste lisible
     * après la suppression du contenu qu'il désigne.
     *
     * @param list<array{type: TypeItem, id: int, quantite?: int}> $items
     * @return list<array{type: string, id: int, quantite: int, nom: string}>
     */
    public function figerItems(array $items): array
    {
        $figes = [];
        foreach ($items as $item) {
            $type = $item['type'];
            $id = (int) $item['id'];

            try {
                $nom = $this->sacService->decrireItem($type, $id)['nom'];
            } catch (\Throwable) {
                // Un item introuvable ne doit pas empêcher de journaliser le fait lui-même.
                $nom = sprintf('%s inconnu (#%d)', TypeCible::depuisItem($type)->label(), $id);
            }

            $figes[] = [
                'type' => $type->value,
                'id' => $id,
                'quantite' => max(1, (int) ($item['quantite'] ?? 1)),
                'nom' => $nom,
            ];
        }

        return $figes;
    }

    /** @return array<string, mixed> la ligne prête pour le repository */
    private function construireLigne(
        TypeEvenement $type,
        ?User $acteur,
        ?User $cibleUser,
        ?TypeCible $cibleType,
        ?int $cibleId,
        int $quantite,
        int $montantOr,
        array $contexte,
    ): array {
        return [
            'type' => $type->value,
            'acteurId' => $acteur?->getId(),
            'cibleUserId' => $cibleUser?->getId(),
            'cibleType' => $cibleType?->value,
            'cibleId' => $cibleId,
            'quantite' => $quantite,
            'montantOr' => $montantOr,
            'contexte' => $contexte === [] ? null : json_encode($contexte, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'creeLe' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    private function signaler(\Throwable $erreur, ?TypeEvenement $type): void
    {
        $this->logger->error('Journal : événement non consigné', [
            'type' => $type?->value,
            'exception' => $erreur,
        ]);
    }
}
