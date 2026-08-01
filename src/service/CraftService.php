<?php

namespace App\service;

use App\Config\CraftConfig;
use App\Entity\Consommable;
use App\Entity\CraftCommande;
use App\Entity\Equipement;
use App\Entity\Objet;
use App\Entity\Recette;
use App\Entity\RecetteIngredient;
use App\Entity\User;
use App\Enum\ModeCraft;
use App\Enum\StatutCraft;
use App\Enum\TypeCible;
use App\Enum\TypeCompteur;
use App\Enum\TypeEvenement;
use App\Enum\TypeItem;
use App\Exception\CraftException;
use App\Exception\MetierException;
use App\Repository\CraftCommandeRepository;
use App\Repository\RecetteIngredientRepository;
use App\Repository\RecetteRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA machine à états de la fabrication : lancement, file d'attente, retrait, annulation.
 * Personne d'autre n'écrit dans `craft_commande`.
 *
 * Quatre principes :
 *
 *  1. **La résolution est PARESSEUSE.** Aucune tâche périodique ne « termine » une
 *     commande : `pretAt` est posé au lancement, et l'état se déduit de l'horloge serveur
 *     quand le joueur revient. Le scheduler tourne à la minute et travaillerait pour des
 *     joueurs déconnectés — c'est la règle déjà retenue pour le tick de donjon et les
 *     rechargements d'interaction.
 *
 *  2. **Les ingrédients sont CONSOMMÉS au lancement**, pas réservés. Une réservation
 *     laisserait les ressources visibles dans le sac pendant la cuisson, donc échangeables
 *     ou vendables selon les chemins, et la moindre faille y dupliquerait des items.
 *     Le débit passe par `SacService`, qui contrôle le DISPONIBLE (possédé − réservé) :
 *     une ressource engagée dans un échange en cours n'est donc pas craftable.
 *
 *  3. **Le recyclage rend depuis un INSTANTANÉ** figé au lancement, jamais depuis la
 *     recette : celle-ci peut être éditée pendant la cuisson.
 *
 *  4. **Rien n'est distribué ici.** La sortie passe par `RecompenseService`, les items
 *     rendus par `SacService`, l'XP par `MetierService`, le karma par `KarmaService`.
 *     Ce service orchestre, il ne duplique pas.
 */
class CraftService
{
    public function __construct(
        private readonly RecetteRepository $recetteRepository,
        private readonly RecetteIngredientRepository $ingredientRepository,
        private readonly CraftCommandeRepository $commandeRepository,
        private readonly SacService $sacService,
        private readonly RecompenseService $recompenseService,
        private readonly MetierService $metierService,
        private readonly KarmaService $karmaService,
        private readonly CompteurJoueurService $compteurJoueurService,
        private readonly JournalService $journalService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Le contenu de l'atelier : les recettes des métiers que le joueur a APPRIS, avec ce
     * qui lui manque pour chacune.
     *
     * Purement informatif — comme `InteractionService::decrire()`, ce qui est renvoyé
     * n'autorise rien : `lancer()` revérifie tout.
     */
    public function atelier(User $user): array
    {
        $progression = $this->metierService->progressionDe($user);
        $niveaux = [];
        foreach ($progression['metiers'] as $ligne) {
            $niveaux[$ligne['metierId']] = $ligne['niveau'];
        }

        $recettes = [];
        foreach ($this->recetteRepository->findBy(['actif' => true], ['nom' => 'ASC']) as $recette) {
            $metierId = $recette->getMetier()?->getId();
            if ($metierId === null || !isset($niveaux[$metierId])) {
                continue; // métier non appris : la recette n'existe pas pour ce joueur
            }

            $recettes[] = $this->decrireRecette($user, $recette, $niveaux[$metierId]);
        }

        return [
            'recettes' => $recettes,
            'commandes' => $this->commandes($user),
            'modes' => CraftConfig::modes(),
            'commandesMax' => CraftConfig::COMMANDES_SIMULTANEES_MAX,
        ];
    }

    /**
     * File d'attente du joueur. `pretAt` est une date SERVEUR : le front recalcule le
     * compte à rebours à partir d'elle plutôt que de décompter un nombre de secondes, qui
     * dériverait dans un onglet en arrière-plan.
     */
    public function commandes(User $user): array
    {
        $commandes = $this->commandeRepository->findBy(
            ['user' => $user, 'statut' => StatutCraft::EN_COURS],
            ['pretAt' => 'ASC']
        );

        return array_map(fn (CraftCommande $commande) => $this->decrireCommande($commande), $commandes);
    }

    /* ------------------------------------------------------------------ */
    /* Lancement                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Débite les ingrédients et met la fabrication en route.
     *
     * @throws CraftException
     */
    public function lancer(User $user, int $recetteId, ModeCraft $mode): array
    {
        $recette = $this->recetteRepository->find($recetteId);
        if ($recette === null || !$recette->isActif()) {
            throw new CraftException("Cette recette n'existe pas.");
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $recette, $mode): array {
            // Verrou pessimiste sur le joueur : sans lui, deux requêtes simultanées
            // passeraient toutes deux sous le plafond de commandes et débiteraient deux
            // fois les mêmes ingrédients.
            $this->entityManager->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);

            $this->verifierMetier($user, $recette);

            $enCours = $this->commandeRepository->count(['user' => $user, 'statut' => StatutCraft::EN_COURS]);
            if ($enCours >= CraftConfig::COMMANDES_SIMULTANEES_MAX) {
                throw new CraftException(sprintf(
                    "Vous menez déjà %d fabrications de front. Retirez-en une avant d'en lancer une autre.",
                    CraftConfig::COMMANDES_SIMULTANEES_MAX
                ));
            }

            $instantane = $this->debiterIngredients($user, $recette);

            $maintenant = new \DateTimeImmutable();
            $secondes = max(1, (int)round($recette->getTempsSecondes() * CraftConfig::multiplicateurTemps($mode)));

            $commande = (new CraftCommande())
                ->setUser($user)
                ->setRecette($recette)
                ->setMode($mode)
                ->setStatut(StatutCraft::EN_COURS)
                ->setLanceeAt($maintenant)
                ->setPretAt($maintenant->modify("+{$secondes} seconds"))
                ->setIngredients($instantane);

            $this->entityManager->persist($commande);
            $this->entityManager->flush();

            return [
                'message' => "Vous mettez « {$recette->getNom()} » en fabrication.",
                'commande' => $this->decrireCommande($commande),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Retrait et annulation                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Ramasse une fabrication terminée : sortie, recyclage, XP de métier, karma.
     *
     * @throws CraftException
     */
    public function retirer(User $user, int $commandeId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $commandeId): array {
            $commande = $this->commandeVerrouillee($user, $commandeId);

            // Le statut est la garde d'idempotence : deux retraits concurrents ne peuvent
            // pas distribuer deux fois la sortie, le second trouve RETIREE.
            if ($commande->getStatut() !== StatutCraft::EN_COURS) {
                throw new CraftException("Cette fabrication a déjà été " . ($commande->getStatut() === StatutCraft::RETIREE ? "retirée." : "annulée."));
            }

            if (!$commande->estPrete()) {
                throw new CraftException("Cette fabrication n'est pas terminée.");
            }

            $recette = $commande->getRecette();
            $messages = [];

            ['rewards' => $rewards] = $this->recompenseService->distribuer($user, $recette->getRecompense());
            if ($rewards !== []) {
                $messages = array_merge($messages, $this->recompenseService->decrireRecompenses($rewards));
            }

            $rendus = $this->recycler($user, $commande);
            foreach ($rendus as $rendu) {
                $messages[] = "Vous récupérez {$rendu['quantite']} {$rendu['nom']}.";
            }

            $metier = null;
            if ($recette->getExperienceMetier() > 0) {
                try {
                    $metier = $this->metierService->gagnerExperience($user, $recette->getMetier(), $recette->getExperienceMetier());
                    $messages[] = "{$recette->getMetier()->getNom()} : +{$recette->getExperienceMetier()} points d'expérience.";
                    if ($metier['niveauxGagnes'] > 0) {
                        $messages[] = "Vous atteignez le niveau {$metier['niveau']} en {$recette->getMetier()->getNom()} !";
                    }
                } catch (MetierException) {
                    // Le joueur a oublié le métier pendant la cuisson : il ramasse son
                    // objet — il l'a payé — mais ne progresse plus dans un métier qu'il
                    // n'exerce plus. Perdre l'objet serait une punition disproportionnée.
                    $messages[] = "Vous n'exercez plus ce métier : la fabrication ne vous apprend rien.";
                }
            }

            $karma = null;
            $ajustement = $this->karmaService->ajuster($user, CraftConfig::karma($commande->getMode()));
            if ($ajustement['delta'] !== 0) {
                $karma = $ajustement;
            }

            // La fabrication n'est comptée qu'ICI, au retrait : c'est le seul moment où
            // quelque chose sort réellement de l'atelier. Compter au lancement laisserait
            // « lancer puis annuler » faire progresser une quête d'artisan gratuitement.
            $this->compteurJoueurService->incrementer(
                $user,
                TypeCompteur::OBJET_FABRIQUE,
                (int)$recette->getId()
            );

            // Journalisé au retrait pour la même raison que le compteur ci-dessus : c'est
            // le moment où quelque chose sort réellement de l'atelier.
            $this->journalService->consigner(
                type: TypeEvenement::CRAFT_TERMINE,
                acteur: $user,
                cibleType: TypeCible::RECETTE,
                cibleId: (int)$recette->getId(),
                quantite: 1,
                contexte: ['mode' => $commande->getMode()->value],
            );

            $commande->setStatut(StatutCraft::RETIREE);
            $commande->setRetireeAt(new \DateTimeImmutable());
            $this->entityManager->persist($commande);
            $this->entityManager->flush();

            return [
                'messages' => $messages === [] ? ["Il ne sort rien de votre atelier."] : $messages,
                'rewards' => $rewards,
                'metier' => $metier,
                'karma' => $karma,
                'commandes' => $this->commandes($user),
            ];
        });
    }

    /**
     * Annule une fabrication NON terminée et rend les ingrédients à l'identique.
     *
     * Après `pretAt`, l'annulation est refusée : l'objet est fait, il faut le retirer.
     * Autoriser l'annulation tardive donnerait le choix, à la fin, entre l'objet et ses
     * matériaux — ce qui reviendrait à ne jamais engager la moindre ressource.
     *
     * @throws CraftException
     */
    public function annuler(User $user, int $commandeId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $commandeId): array {
            $commande = $this->commandeVerrouillee($user, $commandeId);

            if ($commande->getStatut() !== StatutCraft::EN_COURS) {
                throw new CraftException("Cette fabrication n'est plus en cours.");
            }

            if ($commande->estPrete()) {
                throw new CraftException("Cette fabrication est terminée : il faut la retirer.");
            }

            foreach ($commande->getIngredients() as $ingredient) {
                $this->sacService->ajouterItem(
                    $user,
                    TypeItem::from($ingredient['type']),
                    (int)$ingredient['itemId'],
                    (int)$ingredient['quantite']
                );
            }

            $commande->setStatut(StatutCraft::ANNULEE);
            $this->entityManager->persist($commande);
            $this->entityManager->flush();

            return [
                'message' => "Fabrication annulée, les matériaux vous sont rendus.",
                'commandes' => $this->commandes($user),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    /** @throws CraftException */
    private function verifierMetier(User $user, Recette $recette): void
    {
        $metier = $recette->getMetier();
        $niveau = $this->metierService->niveau($user, $metier);

        if ($niveau === 0) {
            throw new CraftException("Il faut avoir appris le métier de {$metier->getNom()} pour cela.");
        }

        if ($niveau < $recette->getNiveauRequis()) {
            throw new CraftException(
                "Il faut être {$metier->getNom()} niveau {$recette->getNiveauRequis()} (vous êtes niveau {$niveau})."
            );
        }
    }

    /**
     * Débite les ingrédients et renvoie l'instantané de ce qui a réellement été pris.
     *
     * @throws CraftException
     */
    private function debiterIngredients(User $user, Recette $recette): array
    {
        // Relu depuis le repository et non depuis la collection de l'entité : après une
        // sauvegarde de l'éditeur, la collection peut être périmée (piège doc §14.5).
        $ingredients = $this->ingredientRepository->findBy(['recette' => $recette]);
        if ($ingredients === []) {
            throw new CraftException("Cette recette est incomplète : elle n'a aucun ingrédient.");
        }

        $instantane = [];
        foreach ($ingredients as $ingredient) {
            $type = $ingredient->getType();
            $itemId = $ingredient->getItemId();
            if ($type === null || $itemId === null) {
                throw new CraftException("Cette recette est incomplète : un ingrédient n'est pas renseigné.");
            }

            try {
                $this->sacService->retirerItem($user, $type, $itemId, $ingredient->getQuantite());
            } catch (\DomainException) {
                // Message reformulé : « Vous n'en possédez que 2 » ne dit pas de QUOI.
                $disponible = $this->sacService->quantiteDisponible($user, $type, $itemId);
                throw new CraftException(sprintf(
                    "Il vous manque %s : %d nécessaires, %d disponibles.",
                    $ingredient->getNom(),
                    $ingredient->getQuantite(),
                    $disponible
                ));
            }

            $instantane[] = [
                'type' => $type->value,
                'itemId' => $itemId,
                'quantite' => $ingredient->getQuantite(),
                'nom' => $ingredient->getNom(),
            ];
        }

        return $instantane;
    }

    /**
     * Rend une part des ingrédients de l'INSTANTANÉ. Arrondi à l'inférieur : à 30 %, un
     * ingrédient unique ne revient jamais — c'est voulu, sinon le recyclage rendrait la
     * fabrication gratuite sur les petites recettes.
     *
     * @return array<int, array{nom: string, quantite: int}>
     */
    private function recycler(User $user, CraftCommande $commande): array
    {
        $pourcentage = CraftConfig::pourcentageRecycle($commande->getMode());
        if ($pourcentage <= 0) {
            return [];
        }

        $rendus = [];
        foreach ($commande->getIngredients() as $ingredient) {
            $quantite = (int)floor((int)$ingredient['quantite'] * $pourcentage / 100);
            if ($quantite < 1) {
                continue;
            }

            $this->sacService->ajouterItem($user, TypeItem::from($ingredient['type']), (int)$ingredient['itemId'], $quantite);
            $rendus[] = ['nom' => $ingredient['nom'], 'quantite' => $quantite];
        }

        return $rendus;
    }

    /** @throws CraftException */
    private function commandeVerrouillee(User $user, int $commandeId): CraftCommande
    {
        $commande = $this->commandeRepository->find($commandeId);
        if ($commande === null || $commande->getUser()?->getId() !== $user->getId()) {
            throw new CraftException("Cette fabrication n'existe pas.");
        }

        // Verrou pessimiste : deux retraits concurrents doivent se sérialiser, sinon la
        // sortie serait distribuée deux fois avant que le statut ne bascule.
        $this->entityManager->find(CraftCommande::class, $commandeId, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($commande);

        return $commande;
    }

    private function decrireRecette(User $user, Recette $recette, int $niveauJoueur): array
    {
        $ingredients = [];
        $realisable = $niveauJoueur >= $recette->getNiveauRequis();

        foreach ($this->ingredientRepository->findBy(['recette' => $recette]) as $ingredient) {
            $type = $ingredient->getType();
            $itemId = $ingredient->getItemId();
            $disponible = ($type !== null && $itemId !== null)
                ? $this->sacService->quantiteDisponible($user, $type, $itemId)
                : 0;

            $ingredients[] = array_merge(
                $this->decrireItem($ingredient->getObjet(), $ingredient->getEquipement(), $ingredient->getConsommable())
                    ?? ['nom' => $ingredient->getNom(), 'type' => $type?->value, 'itemId' => $itemId, 'image' => null, 'position' => null, 'description' => null],
                [
                    'requis' => $ingredient->getQuantite(),
                    'disponible' => $disponible,
                ]
            );

            if ($disponible < $ingredient->getQuantite()) {
                $realisable = false;
            }
        }

        return [
            'id' => $recette->getId(),
            'nom' => $recette->getNom(),
            'description' => $recette->getDescription(),
            'metierId' => $recette->getMetier()->getId(),
            'metier' => $recette->getMetier()->getNom(),
            'niveauRequis' => $recette->getNiveauRequis(),
            'niveauJoueur' => $niveauJoueur,
            'difficulte' => $recette->getDifficulte(),
            'tempsSecondes' => $recette->getTempsSecondes(),
            'experienceMetier' => $recette->getExperienceMetier(),
            'produit' => $this->decrireProduit($recette),
            'ingredients' => $ingredients,
            'realisable' => $realisable,
        ];
    }

    /** Ce que la recette sort, en clair : le front n'a pas à relire la récompense. */
    private function decrireProduit(Recette $recette): ?array
    {
        $recompense = $recette->getRecompense();
        if ($recompense === null) {
            return null;
        }

        $item = $this->decrireItem($recompense->getObjet(), $recompense->getEquipement(), $recompense->getConsommable());
        if ($item === null) {
            return null;
        }

        // Un équipement ne s'empile pas : sa quantité est toujours 1, comme dans
        // RecompenseService.
        $quantite = $item['type'] === TypeItem::EQUIPEMENT->value ? 1 : max(1, (int)$recompense->getQuantity());

        return array_merge($item, ['quantite' => $quantite]);
    }

    /**
     * Identité visuelle d'un item, quelle que soit sa famille : nom, description et image.
     *
     * L'image est renvoyée **brute** (le nom de fichier tel qu'il est en base), accompagnée
     * de la position pour un équipement. Les deux conventions de chemin du jeu
     * (`/img/objet/<image>`, `/img/consommables/<icone>`, `/img/equipement/<position>/<icone>`)
     * vivent côté front dans `itemUtils.js` — les dupliquer ici en ferait une seconde source
     * de vérité, et c'est le back qui divergerait le jour où un dossier change.
     *
     * @return array{type: string, itemId: int|null, nom: string, description: string|null, image: string|null, position: string|null}|null
     */
    private function decrireItem(?Objet $objet, ?Equipement $equipement, ?Consommable $consommable): ?array
    {
        return match (true) {
            $objet !== null => [
                'type' => TypeItem::OBJET->value,
                'itemId' => $objet->getId(),
                'nom' => $objet->getName(),
                'description' => $objet->getDescription(),
                'image' => $objet->getImage(),
                'position' => null,
            ],
            $equipement !== null => [
                'type' => TypeItem::EQUIPEMENT->value,
                'itemId' => $equipement->getId(),
                'nom' => $equipement->getNom(),
                'description' => $equipement->getDescription(),
                'image' => $equipement->getIcone(),
                'position' => $equipement->getPositionEquipement()?->getName(),
            ],
            $consommable !== null => [
                'type' => TypeItem::CONSOMMABLE->value,
                'itemId' => $consommable->getId(),
                'nom' => $consommable->getNom(),
                'description' => $consommable->getDescription(),
                'image' => $consommable->getIcone(),
                'position' => null,
            ],
            default => null,
        };
    }

    private function decrireCommande(CraftCommande $commande): array
    {
        $recette = $commande->getRecette();

        return [
            'id' => $commande->getId(),
            'recetteId' => $recette?->getId(),
            'nom' => $recette?->getNom() ?? '???',
            'mode' => $commande->getMode()->value,
            'modeLabel' => $commande->getMode()->label(),
            'statut' => $commande->getStatut()->value,
            // Dates SERVEUR : le front recalcule le compte à rebours ET l'avancement depuis
            // elles, jamais en décomptant des secondes reçues. `lanceeAt` est l'origine de la
            // barre de progression — sans elle, le front ne connaît pas la durée totale, le
            // mode ayant pu la multiplier.
            'lanceeAt' => $commande->getLanceeAt()->format(\DateTimeInterface::ATOM),
            'pretAt' => $commande->getPretAt()->format(\DateTimeInterface::ATOM),
            'prete' => $commande->estPrete(),
            'produit' => $recette !== null ? $this->decrireProduit($recette) : null,
            'ingredients' => $commande->getIngredients(),
        ];
    }
}
