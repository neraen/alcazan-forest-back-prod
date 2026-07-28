<?php

namespace App\service;

use App\Entity\BossRecompense;
use App\Entity\Recompense;
use App\Entity\User;
use App\Enum\TypeItem;

/**
 * UNIQUE point de conversion « ligne Recompense » -> items dans le sac + or + XP.
 * Utilisé par les quêtes (QuestProgressionService), le butin de boss et les coffres
 * de donjon : la logique de distribution ne doit exister qu'ici.
 *
 * Ne flushe pas et n'ouvre pas de transaction — l'appelant fournit la transaction,
 * même contrat que SacService (qui est le seul à muter le sac et l'or).
 */
class RecompenseService
{
    public function __construct(
        private readonly SacService $sacService,
        private readonly LevelingService $levelingService
    ) {}

    /**
     * Distribue une récompense au joueur.
     *
     * `$multiplicateur` (≥ 1) démultiplie ce qui se COMPTE — quantités d'items et or.
     * Il ne touche ni l'expérience de personnage, ni les équipements : on ne gagne pas
     * trois niveaux ni trois épées parce qu'on a raclé un buisson. Ce paramètre existe
     * pour la récolte intensive (lot 2) sans que quiconque d'autre ait à empiler des
     * appels ou à écrire dans le sac — ce service reste l'unique point de distribution.
     *
     * @return array{rewards: list<array{type: string, id: ?int, label: string, quantity: int}>, playerXp: ?array}
     *         'rewards' alimente le feedback front, 'playerXp' vaut
     *         {experience, level, experienceMax} si de l'XP a été donnée (sinon null),
     *         pour rafraîchir la barre sans rechargement.
     *
     *         `id` est l'identifiant de l'item distribué (null pour l'or et l'XP, qui
     *         n'en ont pas). Il existe pour que l'appelant sache CE QU'IL a réellement
     *         donné sans relire la récompense ni redériver la quantité multipliée —
     *         c'est ce dont la récolte a besoin pour compter les ressources ramassées.
     */
    public function distribuer(User $user, ?Recompense $recompense, int $multiplicateur = 1): array
    {
        if ($recompense === null) {
            return ['rewards' => [], 'playerXp' => null];
        }

        $rewards = [];
        $playerXp = null;
        $multiplicateur = max(1, $multiplicateur);
        $quantity = max(1, (int)$recompense->getQuantity()) * $multiplicateur;

        if ($recompense->getEquipement() !== null) {
            $this->sacService->ajouterItem($user, TypeItem::EQUIPEMENT, $recompense->getEquipement()->getId(), 1);
            $rewards[] = ['type' => 'equipement', 'id' => $recompense->getEquipement()->getId(), 'label' => $recompense->getEquipement()->getNom(), 'quantity' => 1];
        }

        if ($recompense->getConsommable() !== null) {
            $this->sacService->ajouterItem($user, TypeItem::CONSOMMABLE, $recompense->getConsommable()->getId(), $quantity);
            $rewards[] = ['type' => 'consommable', 'id' => $recompense->getConsommable()->getId(), 'label' => $recompense->getConsommable()->getNom(), 'quantity' => $quantity];
        }

        if ($recompense->getObjet() !== null) {
            $this->sacService->ajouterItem($user, TypeItem::OBJET, $recompense->getObjet()->getId(), $quantity);
            $rewards[] = ['type' => 'objet', 'id' => $recompense->getObjet()->getId(), 'label' => $recompense->getObjet()->getName(), 'quantity' => $quantity];
        }

        if ($recompense->getMoney() !== null && $recompense->getMoney() > 0) {
            $or = $recompense->getMoney() * $multiplicateur;
            $this->sacService->crediterOr($user, $or);
            $rewards[] = ['type' => 'or', 'id' => null, 'label' => "pièces d'or", 'quantity' => $or];
        }

        if ($recompense->getExperience() !== null && $recompense->getExperience() > 0) {
            $playerXp = $this->levelingService->giveExperienceToAPlayer($recompense->getExperience(), $user->getId());
            $rewards[] = ['type' => 'experience', 'id' => null, 'label' => "points d'expérience", 'quantity' => $recompense->getExperience()];
        }

        return ['rewards' => $rewards, 'playerXp' => $playerXp];
    }

    /**
     * Tire une récompense dans une table pondérée par `taux`.
     *
     * Le total des taux est ramené à 100 minimum : une table dont les taux somment
     * à moins de 100 comporte donc une part de « rien » (ex. 30 + 30 => 40 % de bredouille),
     * ce qui permet à l'admin de doser la générosité sans table fictive.
     *
     * @param BossRecompense[] $table
     */
    public function tirerDansTable(array $table): ?Recompense
    {
        $total = 0;
        foreach ($table as $ligne) {
            $total += max(0, (int)$ligne->getTaux());
        }

        if ($total <= 0) {
            return null;
        }

        $tirage = mt_rand(1, max(100, $total));
        $cumul = 0;
        foreach ($table as $ligne) {
            $cumul += max(0, (int)$ligne->getTaux());
            if ($tirage <= $cumul) {
                return $ligne->getRecompense();
            }
        }

        return null;
    }

    /** Phrase de feedback joueur pour une liste de récompenses distribuées. */
    public function decrireRecompenses(array $rewards): array
    {
        return array_map(
            fn (array $reward) => $reward['type'] === 'equipement'
                ? "Vous obtenez {$reward['label']}."
                : "Vous obtenez {$reward['quantity']} {$reward['label']}.",
            $rewards
        );
    }
}
