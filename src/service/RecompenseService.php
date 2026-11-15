<?php

namespace App\service;

use App\Entity\BossRecompense;
use App\Entity\Recompense;
use App\Entity\User;
use App\Enum\TypeCumul;
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
        private readonly LevelingService $levelingService,
        private readonly CumulJoueurService $cumulJoueurService
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
            // Compté ICI parce que ce service est l'unique point de conversion d'une
            // récompense : quêtes, butin de boss, coffres et sortie d'atelier passent tous
            // par lui. Sans cette ligne, `OR_GAGNE` ne compterait que les ventes et les
            // échanges, et annoncerait donc un total faux à tout joueur qui a fait une quête.
            // Pas de double comptage : la vente et l'hôtel des ventes créditent par
            // `SacService` directement, jamais via une `Recompense`.
            $this->cumulJoueurService->ajouter($user, TypeCumul::OR_GAGNE, $or);
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

    /**
     * Ce que rapporterait une récompense, SANS rien distribuer — pour l'annoncer (journal
     * de quêtes). Même forme que les `rewards` de `distribuer()`, plus le nom de fichier
     * BRUT de l'image (et la position d'un équipement, dont dépend le dossier) : les
     * chemins vivent côté client.
     *
     * Vit ici et pas chez l'appelant pour que l'annonce et la distribution ne puissent pas
     * diverger (quantité ramenée à 1, XP et or ignorés à zéro).
     *
     * @return list<array{type: string, id: ?int, label: string, quantity: int, image: ?string, position: ?string}>
     */
    public function apercu(?Recompense $recompense): array
    {
        if ($recompense === null) {
            return [];
        }

        $apercu = [];
        $quantity = max(1, (int)$recompense->getQuantity());

        if ($recompense->getEquipement() !== null) {
            $equipement = $recompense->getEquipement();
            $apercu[] = ['type' => 'equipement', 'id' => $equipement->getId(), 'label' => (string)$equipement->getNom(), 'quantity' => 1,
                'image' => $equipement->getIcone(), 'position' => $equipement->getPositionEquipement()?->getName()];
        }

        if ($recompense->getConsommable() !== null) {
            $consommable = $recompense->getConsommable();
            $apercu[] = ['type' => 'consommable', 'id' => $consommable->getId(), 'label' => (string)$consommable->getNom(), 'quantity' => $quantity,
                'image' => $consommable->getIcone(), 'position' => null];
        }

        if ($recompense->getObjet() !== null) {
            $objet = $recompense->getObjet();
            $apercu[] = ['type' => 'objet', 'id' => $objet->getId(), 'label' => (string)$objet->getName(), 'quantity' => $quantity,
                'image' => $objet->getImage(), 'position' => null];
        }

        if ($recompense->getMoney() !== null && $recompense->getMoney() > 0) {
            $apercu[] = ['type' => 'or', 'id' => null, 'label' => "pièces d'or", 'quantity' => (int)$recompense->getMoney(), 'image' => null, 'position' => null];
        }

        if ($recompense->getExperience() !== null && $recompense->getExperience() > 0) {
            $apercu[] = ['type' => 'experience', 'id' => null, 'label' => "points d'expérience", 'quantity' => (int)$recompense->getExperience(), 'image' => null, 'position' => null];
        }

        return $apercu;
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
