<?php

namespace App\service;

use App\Config\ArtisanatConfig;
use App\Config\CraftConfig;
use App\Entity\Metier;
use App\Entity\Objet;
use App\Entity\Recette;
use App\Entity\RecetteIngredient;
use App\Entity\Recompense;
use App\Enum\FamilleMetier;
use App\Exception\CraftException;
use App\Repository\ConsommableRepository;
use App\Repository\CraftCommandeRepository;
use App\Repository\EquipementRepository;
use App\Repository\InteractionRepository;
use App\Repository\MetierRepository;
use App\Repository\ObjetRepository;
use App\Repository\PnjRepository;
use App\Repository\RecetteIngredientRepository;
use App\Repository\RecetteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ArtisanatMaker : les trois faces du contenu d'artisanat — métiers, ressources, recettes —
 * lues et sauvegardées en UNE transaction avec des **ids stables**, même patron que
 * QuestEditorService, DonjonEditorService et InteractionEditorService.
 *
 * « Ids stables » : les lignes envoyées avec un id sont mises à jour, celles sans id créées,
 * celles absentes supprimées. On ne repart jamais de zéro — `craft_commande` référence les
 * recettes, tout recréer casserait les fabrications en cours.
 *
 * ⚠️ Piège Doctrine déjà payé (doc §14.5) : ingrédients et recettes sont relus depuis LEUR
 * REPOSITORY, jamais depuis la collection de l'entité. Après une sauvegarde, la collection
 * a été chargée AVANT l'insertion et rend un état périmé.
 */
class ArtisanatEditorService
{
    public function __construct(
        private readonly MetierRepository $metierRepository,
        private readonly RecetteRepository $recetteRepository,
        private readonly RecetteIngredientRepository $ingredientRepository,
        private readonly CraftCommandeRepository $commandeRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EquipementRepository $equipementRepository,
        private readonly ConsommableRepository $consommableRepository,
        private readonly PnjRepository $pnjRepository,
        private readonly InteractionRepository $interactionRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /** Les trois listes de l'écran, en un seul appel. */
    public function lister(): array
    {
        return [
            'metiers' => array_map(fn (Metier $metier) => [
                'id' => $metier->getId(),
                'nom' => $metier->getNom(),
                'famille' => $metier->getFamille()->value,
                'niveauMax' => $metier->getNiveauMax(),
                'recettes' => $this->recetteRepository->count(['metier' => $metier]),
                'ressources' => $this->objetRepository->count(['metier' => $metier]),
            ], $this->metierRepository->findBy([], ['nom' => 'ASC'])),

            // ⚠️ Cette liste EST le formulaire d'édition : le front y puise la fiche qu'il
            // renvoie ensuite à `sauvegarderRessource`. Tout champ omis ici revient donc vide
            // et ÉCRASE la valeur en base — c'est ce qui effaçait description et image dès
            // qu'on rouvrait un objet existant.
            'ressources' => array_map(fn (Objet $objet) => [
                'id' => $objet->getId(),
                'nom' => $objet->getName(),
                'description' => $objet->getDescription(),
                'image' => $objet->getImage(),
                'metier' => $objet->getMetier()?->getNom(),
                'metierId' => $objet->getMetier()?->getId(),
                'niveauRessource' => $objet->getNiveauRessource(),
                'prixVente' => $objet->getPrixVente(),
            ], $this->objetRepository->findBy([], ['name' => 'ASC'])),

            'recettes' => array_map(fn (Recette $recette) => [
                'id' => $recette->getId(),
                'nom' => $recette->getNom(),
                'metier' => $recette->getMetier()?->getNom(),
                'niveauRequis' => $recette->getNiveauRequis(),
                'actif' => $recette->isActif(),
                'enCours' => $this->commandeRepository->count(['recette' => $recette]),
            ], $this->recetteRepository->findBy([], ['nom' => 'ASC'])),
        ];
    }

    /** @throws CraftException */
    public function metierPourEditeur(int $metierId): array
    {
        $metier = $this->trouverMetier($metierId);

        return [
            'id' => $metier->getId(),
            'nom' => $metier->getNom(),
            'description' => $metier->getDescription(),
            'icone' => $metier->getIcone(),
            'famille' => $metier->getFamille()->value,
            'niveauMax' => $metier->getNiveauMax(),
            'maitres' => array_map(fn ($pnj) => $pnj->getId(), $metier->getMaitres()->toArray()),
        ];
    }

    /** @throws CraftException */
    public function recettePourEditeur(int $recetteId): array
    {
        $recette = $this->recetteRepository->find($recetteId);
        if ($recette === null) {
            throw new CraftException("Recette introuvable.");
        }

        $recompense = $recette->getRecompense();

        return [
            'recette' => [
                'id' => $recette->getId(),
                'nom' => $recette->getNom(),
                'description' => $recette->getDescription(),
                'metierId' => $recette->getMetier()?->getId(),
                'niveauRequis' => $recette->getNiveauRequis(),
                'difficulte' => $recette->getDifficulte(),
                'tempsSecondes' => $recette->getTempsSecondes(),
                'experienceMetier' => $recette->getExperienceMetier(),
                'actif' => $recette->isActif(),
            ],
            'produit' => [
                'objetId' => $recompense?->getObjet()?->getId(),
                'equipementId' => $recompense?->getEquipement()?->getId(),
                'consommableId' => $recompense?->getConsommable()?->getId(),
                'quantity' => $recompense?->getQuantity(),
            ],
            // Relu depuis le repository : après une sauvegarde, la collection de l'entité
            // est périmée et rendrait zéro ingrédient.
            'ingredients' => array_map(fn (RecetteIngredient $ingredient) => [
                'id' => $ingredient->getId(),
                'objetId' => $ingredient->getObjet()?->getId(),
                'equipementId' => $ingredient->getEquipement()?->getId(),
                'consommableId' => $ingredient->getConsommable()?->getId(),
                'quantite' => $ingredient->getQuantite(),
            ], $this->ingredientRepository->findBy(['recette' => $recette], ['id' => 'ASC'])),
            'experienceSuggeree' => self::experienceSuggeree($recette->getNiveauRequis(), $recette->getDifficulte()),
        ];
    }

    /** Catalogues des selects, en un seul appel. */
    public function referentiels(): array
    {
        return [
            'metiers' => array_map(fn (Metier $m) => ['id' => $m->getId(), 'nom' => $m->getNom(), 'famille' => $m->getFamille()->value],
                $this->metierRepository->findBy([], ['nom' => 'ASC'])),
            'objets' => $this->nommer($this->objetRepository->findBy([], ['name' => 'ASC']), 'getName'),
            'equipements' => $this->nommer($this->equipementRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'consommables' => $this->nommer($this->consommableRepository->findBy([], ['nom' => 'ASC']), 'getNom'),
            'pnjs' => $this->nommer($this->pnjRepository->findBy(['type' => 'metier'], ['name' => 'ASC']), 'getName'),
        ];
    }

    /**
     * Ce que le front doit savoir pour rendre ses formulaires — familles, plafonds, modes de
     * fabrication. Même patron que `InteractionConfig` : ajouter une famille de métier ou un
     * mode de craft ne demande pas de toucher au front.
     */
    public function config(): array
    {
        return [
            'familles' => array_map(fn (FamilleMetier $f) => ['value' => $f->value, 'label' => $f->label()],
                FamilleMetier::cases()),
            'plafonds' => ArtisanatConfig::plafonds(),
            'modesCraft' => CraftConfig::modes(),
            'commandesMax' => CraftConfig::COMMANDES_SIMULTANEES_MAX,
        ];
    }

    /**
     * Suggestion d'XP de métier pour une recette. **Suggestion et non règle** : l'auteur
     * tranche. Enfermer l'équilibrage dans une formule du code, c'est devoir redéployer
     * pour retoucher un chiffre.
     */
    public static function experienceSuggeree(int $niveauRequis, int $difficulte): int
    {
        return max(5, (int)round($niveauRequis * 2.5 * $difficulte));
    }

    /* ------------------------------------------------------------------ */
    /* Sauvegarde                                                          */
    /* ------------------------------------------------------------------ */

    /** @throws CraftException */
    public function sauvegarderMetier(array $data): array
    {
        $id = $this->entityManager->wrapInTransaction(function () use ($data): int {
            $metier = isset($data['id']) && $data['id'] > 0
                ? $this->trouverMetier((int)$data['id'])
                : new Metier();

            $nom = trim((string)($data['nom'] ?? ''));
            if ($nom === '') {
                throw new CraftException("Le nom du métier est obligatoire.");
            }

            $famille = FamilleMetier::tryFrom((string)($data['famille'] ?? ''));
            if ($famille === null) {
                throw new CraftException("Famille de métier inconnue.");
            }

            $metier->setNom($nom)
                ->setDescription($data['description'] ?? null)
                ->setIcone($data['icone'] ?? null)
                ->setFamille($famille)
                ->setNiveauMax(max(1, (int)($data['niveauMax'] ?? 200)));

            $this->entityManager->persist($metier);
            $this->entityManager->flush(); // id nécessaire pour rattacher les maîtres

            $this->resynchroniserMaitres($metier, $data['maitres'] ?? []);
            $this->entityManager->flush();

            return $metier->getId();
        });

        return $this->metierPourEditeur($id);
    }

    /** @throws CraftException */
    public function sauvegarderRessource(array $data): array
    {
        $this->entityManager->wrapInTransaction(function () use ($data): void {
            $objet = isset($data['id']) && $data['id'] > 0 ? $this->objetRepository->find((int)$data['id']) : new Objet();
            if ($objet === null) {
                throw new CraftException("Objet introuvable.");
            }

            $nom = trim((string)($data['nom'] ?? ''));
            if ($nom === '') {
                throw new CraftException("Le nom de la ressource est obligatoire.");
            }

            // `metierId` nul est légitime : c'est ainsi qu'on retire à un objet son statut
            // de ressource sans le supprimer.
            $metier = isset($data['metierId']) && $data['metierId'] > 0
                ? $this->trouverMetier((int)$data['metierId'])
                : null;

            $objet->setName($nom)
                ->setDescription($data['description'] ?? null)
                ->setPrixVente(isset($data['prixVente']) ? max(0, (int)$data['prixVente']) : null)
                ->setImage($data['image'] ?? null)
                ->setMetier($metier)
                ->setNiveauRessource(max(0, (int)($data['niveauRessource'] ?? 0)));

            $this->entityManager->persist($objet);
            $this->entityManager->flush();
        });

        return $this->lister();
    }

    /** @throws CraftException */
    public function sauvegarderRecette(array $data): array
    {
        $id = $this->entityManager->wrapInTransaction(function () use ($data): int {
            $fiche = $data['recette'] ?? [];
            $recette = isset($fiche['id']) && $fiche['id'] > 0
                ? $this->recetteRepository->find((int)$fiche['id'])
                : new Recette();
            if ($recette === null) {
                throw new CraftException("Recette introuvable.");
            }

            $nom = trim((string)($fiche['nom'] ?? ''));
            if ($nom === '') {
                throw new CraftException("Le nom de la recette est obligatoire.");
            }

            $recette->setNom($nom)
                ->setDescription($fiche['description'] ?? null)
                ->setMetier($this->trouverMetier((int)($fiche['metierId'] ?? 0)))
                ->setNiveauRequis((int)($fiche['niveauRequis'] ?? 1))
                ->setDifficulte((int)($fiche['difficulte'] ?? 1))
                ->setTempsSecondes((int)($fiche['tempsSecondes'] ?? 60))
                ->setExperienceMetier((int)($fiche['experienceMetier'] ?? 0))
                ->setActif((bool)($fiche['actif'] ?? true));

            $this->entityManager->persist($recette);
            $this->entityManager->flush(); // id nécessaire pour rattacher les ingrédients

            $this->upsertProduit($recette, $data['produit'] ?? []);
            $this->upsertIngredients($recette, $data['ingredients'] ?? []);
            $this->entityManager->flush();

            return $recette->getId();
        });

        return $this->recettePourEditeur($id);
    }

    /** @throws CraftException */
    public function supprimerRecette(int $recetteId): void
    {
        $recette = $this->recetteRepository->find($recetteId);
        if ($recette === null) {
            throw new CraftException("Recette introuvable.");
        }

        // Une commande référence sa recette : la supprimer casserait une fabrication qu'un
        // joueur a déjà payée. Le message dit quoi faire à la place.
        $enCours = $this->commandeRepository->count(['recette' => $recette]);
        if ($enCours > 0) {
            throw new CraftException(
                "Des joueurs ont {$enCours} fabrication" . ($enCours > 1 ? 's' : '')
                . " liée" . ($enCours > 1 ? 's' : '') . " à cette recette. Désactivez-la plutôt que de la supprimer."
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($recette): void {
            foreach ($this->ingredientRepository->findBy(['recette' => $recette]) as $ingredient) {
                $this->entityManager->remove($ingredient);
            }
            $this->entityManager->remove($recette);
            $this->entityManager->flush();
        });
    }

    /** @throws CraftException */
    public function supprimerMetier(int $metierId): void
    {
        $metier = $this->trouverMetier($metierId);

        $recettes = $this->recetteRepository->count(['metier' => $metier]);
        $ressources = $this->objetRepository->count(['metier' => $metier]);
        $interactions = $this->interactionRepository->count(['metier' => $metier]);

        if ($recettes + $ressources + $interactions > 0) {
            throw new CraftException(sprintf(
                "Ce métier est encore employé (%d recette(s), %d ressource(s), %d case(s) de récolte). "
                . "Détachez-les avant de le supprimer.",
                $recettes, $ressources, $interactions
            ));
        }

        $this->entityManager->wrapInTransaction(function () use ($metier): void {
            $this->entityManager->remove($metier);
            $this->entityManager->flush();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    /** @throws CraftException */
    private function trouverMetier(int $metierId): Metier
    {
        $metier = $this->metierRepository->find($metierId);
        if ($metier === null) {
            throw new CraftException("Métier introuvable.");
        }

        return $metier;
    }

    /** RESYNCHRONISE la liste des maîtres, retraits compris — jamais un simple ajout. */
    private function resynchroniserMaitres(Metier $metier, array $pnjIds): void
    {
        $voulus = array_map('intval', $pnjIds);

        foreach ($metier->getMaitres() as $pnj) {
            if (!in_array($pnj->getId(), $voulus, true)) {
                $pnj->removeMetier($metier);
                $this->entityManager->persist($pnj);
            }
        }

        foreach ($voulus as $pnjId) {
            $pnj = $this->pnjRepository->find($pnjId);
            if ($pnj !== null) {
                $pnj->addMetier($metier);
                $this->entityManager->persist($pnj);
            }
        }
    }

    /** La sortie est mise à jour EN PLACE : la recréer changerait son id pour rien. */
    private function upsertProduit(Recette $recette, array $data): void
    {
        $vide = empty($data['objetId']) && empty($data['equipementId']) && empty($data['consommableId']);

        if ($vide) {
            $recette->setRecompense(null);

            return;
        }

        $recompense = $recette->getRecompense() ?? new Recompense();
        $recompense->setObjet(!empty($data['objetId']) ? $this->objetRepository->find((int)$data['objetId']) : null);
        $recompense->setEquipement(!empty($data['equipementId']) ? $this->equipementRepository->find((int)$data['equipementId']) : null);
        $recompense->setConsommable(!empty($data['consommableId']) ? $this->consommableRepository->find((int)$data['consommableId']) : null);
        $recompense->setQuantity(max(1, (int)($data['quantity'] ?? 1)));

        $this->entityManager->persist($recompense);
        $recette->setRecompense($recompense);
    }

    /** Ids stables : mise à jour, création, et suppression des absents. */
    private function upsertIngredients(Recette $recette, array $donnees): void
    {
        // Relu depuis le repository, pas depuis la collection (voir en-tête de classe).
        $existants = [];
        foreach ($this->ingredientRepository->findBy(['recette' => $recette]) as $ingredient) {
            $existants[$ingredient->getId()] = $ingredient;
        }

        $gardes = [];
        foreach ($donnees as $ligne) {
            $id = isset($ligne['id']) ? (int)$ligne['id'] : 0;
            $ingredient = $id > 0 && isset($existants[$id]) ? $existants[$id] : new RecetteIngredient();
            $ingredient->setRecette($recette);

            $ingredient->setObjet(!empty($ligne['objetId']) ? $this->objetRepository->find((int)$ligne['objetId']) : null);
            $ingredient->setEquipement(!empty($ligne['equipementId']) ? $this->equipementRepository->find((int)$ligne['equipementId']) : null);
            $ingredient->setConsommable(!empty($ligne['consommableId']) ? $this->consommableRepository->find((int)$ligne['consommableId']) : null);
            $ingredient->setQuantite((int)($ligne['quantite'] ?? 1));

            if ($ingredient->getType() === null) {
                throw new CraftException("Chaque ingrédient doit désigner un objet, un équipement ou un consommable.");
            }

            $this->entityManager->persist($ingredient);
            if ($id > 0) {
                $gardes[$id] = true;
            }
        }

        foreach ($existants as $id => $ingredient) {
            if (!isset($gardes[$id])) {
                $this->entityManager->remove($ingredient);
            }
        }
    }

    private function nommer(array $entites, string $getter): array
    {
        return array_map(fn (object $entite) => ['id' => $entite->getId(), 'nom' => $entite->$getter()], $entites);
    }
}
