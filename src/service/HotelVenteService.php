<?php

namespace App\service;

use App\Config\HotelVenteConfig;
use App\Entity\HotelVente;
use App\Entity\User;
use App\Enum\StatutHotelVente;
use App\Enum\TriHotelVente;
use App\Enum\TypeCumul;
use App\Enum\TypeEvenement;
use App\Enum\TypeItem;
use App\Exception\HotelVenteIndisponibleException;
use App\Repository\HotelVenteRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Machine à états UNIQUE de l'hôtel des ventes : personne d'autre n'écrit dans `hotel_vente`.
 *
 * Le marché est ASYNCHRONE, c'est toute sa raison d'être : on dépose un lot et on repart, un
 * autre joueur l'achète pendant qu'on est déconnecté. D'où trois différences avec l'échange :
 *
 *  1. SÉQUESTRE — l'objet est sorti du sac au dépôt (et non réservé). Une réservation de deux
 *     jours dans `reservation_ressource`, dont le seul usage dure cinq minutes, laisserait au
 *     joueur un objet visible mais inutilisable sans explication.
 *  2. FRAIS DE DÉPÔT — le vendeur paie à la mise en vente, jamais à la vente, et n'est pas
 *     remboursé. L'or prélevé disparaît du jeu (puits monétaire) et afficher un prix délirant
 *     coûte quelque chose.
 *  3. PAS DE `version` — une annonce n'est pas co-éditée, seul son statut bascule. La course
 *     entre deux acheteurs se règle par verrou pessimiste plus test du statut ; le prix attendu
 *     envoyé par le client n'est qu'une garde d'écran périmé.
 *
 * Comme SacService, ce service ne décide d'aucun montant à partir du client : le prix vient de
 * la base, les frais de HotelVenteConfig.
 */
class HotelVenteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HotelVenteRepository $hotelVenteRepository,
        private readonly SacService $sacService,
        private readonly HotelVenteNormalizer $normalizer,
        private readonly JournalService $journalService,
        private readonly CumulJoueurService $cumulJoueurService,
        private readonly LoggerInterface $logger
    ) {}

    /* ------------------------------------------------------------------ lecture */

    /**
     * Une page du catalogue. Les annonces du lecteur y figurent (il doit voir sa concurrence),
     * mais `estMien` les rend inachetables côté client — et `acheter` les refuse de toute façon.
     */
    public function catalogue(
        User $user,
        ?TypeItem $type,
        ?string $recherche,
        TriHotelVente $tri,
        int $page
    ): array {
        $recherche = trim((string) $recherche);
        $idsParType = $recherche === '' ? null : $this->sacService->rechercherItemsParNom($recherche);

        $resultat = $this->hotelVenteRepository->catalogue(
            $type,
            $idsParType,
            $tri,
            $page,
            HotelVenteConfig::ANNONCES_PAR_PAGE
        );

        $pages = (int) ceil($resultat['total'] / HotelVenteConfig::ANNONCES_PAR_PAGE);

        return [
            'annonces' => $this->normalizer->normalizeListe($resultat['annonces'], $user),
            'total' => $resultat['total'],
            'page' => max(1, $page),
            'pages' => max(1, $pages),
            'curseurs' => HotelVenteConfig::curseurs(),
            'money' => (int) $user->getMoney(),
        ];
    }

    /** Les lots du joueur : ce qui est encore en vente, et ce qui s'est conclu récemment. */
    public function mesVentes(User $user): array
    {
        $actives = $this->hotelVenteRepository->findActivesDe($user);

        return [
            'actives' => $this->normalizer->normalizeListe($actives, $user),
            'historique' => $this->normalizer->normalizeListe(
                $this->hotelVenteRepository->findHistoriqueDe($user, 20),
                $user
            ),
            'emplacementsUtilises' => count($actives),
            'curseurs' => HotelVenteConfig::curseurs(),
            'money' => (int) $user->getMoney(),
        ];
    }

    /* ------------------------------------------------------------------ écriture */

    /**
     * Dépose un lot : les frais sont prélevés et l'objet quitte le sac, dans la même
     * transaction. Un sac qui n'a pas la quantité, ou une bourse qui n'a pas les frais, et
     * rien ne se produit.
     *
     * @throws \DomainException prix hors bornes, plafond atteint, ressources insuffisantes
     */
    public function mettreEnVente(User $user, TypeItem $type, int $itemId, int $quantite, int $prix): array
    {
        if ($quantite < 1) {
            throw new \DomainException("Quantité invalide.");
        }
        if ($prix < HotelVenteConfig::PRIX_MIN || $prix > HotelVenteConfig::PRIX_MAX) {
            throw new \DomainException(sprintf(
                "Le prix doit être compris entre %d et %d pièces d'or.",
                HotelVenteConfig::PRIX_MIN,
                HotelVenteConfig::PRIX_MAX
            ));
        }

        $frais = HotelVenteConfig::fraisDepot($prix);

        $resultat = $this->entityManager->wrapInTransaction(function () use ($user, $type, $itemId, $quantite, $prix, $frais): array {
            if ($this->hotelVenteRepository->compterActivesDe($user) >= HotelVenteConfig::ANNONCES_MAX_PAR_JOUEUR) {
                throw new \DomainException(sprintf(
                    "Vous ne pouvez pas avoir plus de %d lots en vente simultanément.",
                    HotelVenteConfig::ANNONCES_MAX_PAR_JOUEUR
                ));
            }

            // Le nom est lu AVANT le retrait : après, la ligne d'inventaire peut avoir disparu.
            $fiche = $this->sacService->decrireItem($type, $itemId);

            // Séquestre : l'objet quitte le sac. `retirerItem` contrôle le DISPONIBLE, donc
            // refuse ce qui est déjà engagé dans un échange en cours.
            $this->sacService->retirerItem($user, $type, $itemId, $quantite);
            $this->sacService->debiterOr($user, $frais);
            // Les frais sont de l'or DÉPENSÉ, et définitivement : c'est le puits monétaire.
            $this->cumulJoueurService->ajouter($user, TypeCumul::OR_DEPENSE, $frais);

            $annonce = (new HotelVente())
                ->setVendeur($user)
                ->setType($type)
                ->setItemId($itemId)
                ->setQuantite($quantite)
                ->setPrix($prix)
                ->setFraisDepot($frais);

            $this->entityManager->persist($annonce);
            $this->entityManager->flush();

            // Les frais sont dans le contexte et non dans `montantOr` : `montantOr` porte le
            // PRIX demandé, pour que le tableau de bord distingue ce qui est mis en vente de
            // ce qui est réellement détruit. Les frais, eux, sont un puits monétaire (doc §20)
            // que ce champ rend mesurable pour la première fois.
            $this->journalService->consigner(
                type: TypeEvenement::HDV_DEPOT,
                acteur: $user,
                montantOr: $prix,
                contexte: [
                    'annonceId' => $annonce->getId(),
                    'items' => [[
                        'type' => $type->value,
                        'id' => $itemId,
                        'quantite' => $quantite,
                        'nom' => $fiche['nom'],
                    ]],
                    'fraisDepot' => $frais,
                ],
            );

            return [
                'annonce' => $annonce,
                'message' => sprintf(
                    "%s ×%d mis en vente pour %d po. Frais de dépôt : %d po.",
                    $fiche['nom'],
                    $quantite,
                    $prix,
                    $frais
                ),
            ];
        });

        return [
            'annonce' => $this->normalizer->normalize($resultat['annonce'], $user),
            'money' => (int) $user->getMoney(),
            'message' => $resultat['message'],
        ];
    }

    /**
     * Achète un lot entier.
     *
     * @throws HotelVenteIndisponibleException lot déjà parti, expiré, ou prix périmé (409)
     * @throws \DomainException                or insuffisant, ou son propre lot (400)
     */
    public function acheter(User $user, int $annonceId, int $prixAttendu): array
    {
        // Expiration paresseuse AVANT d'ouvrir la transaction d'achat : la restitution au
        // vendeur doit être commitée pour elle-même. La faire dans la transaction d'achat, qui
        // se termine par un refus, la ferait annuler avec lui — l'objet resterait séquestré.
        $this->expirerAnnonce($annonceId);

        $resultat = $this->entityManager->wrapInTransaction(function () use ($user, $annonceId, $prixAttendu): array {
            $annonce = $this->annonceVerrouillee($annonceId);
            if ($annonce === null) {
                throw new HotelVenteIndisponibleException(null, "Ce lot n'existe plus.");
            }

            if (!$annonce->estOuverte()) {
                throw new HotelVenteIndisponibleException(
                    $this->normalizer->normalize($annonce, $user),
                    "Ce lot vient d'être vendu ou retiré."
                );
            }
            if ($annonce->estExpire()) {
                throw new HotelVenteIndisponibleException(
                    $this->normalizer->normalize($annonce, $user),
                    "Ce lot a expiré."
                );
            }

            $vendeur = $annonce->getVendeur();
            if ($vendeur->getId() === $user->getId()) {
                throw new \DomainException("Vous ne pouvez pas acheter votre propre lot. Retirez-le pour le récupérer.");
            }

            // Garde d'écran périmé : le client renvoie le prix qu'il a lu. Le serveur ne s'en
            // sert JAMAIS pour débiter — il fait foi sur `annonce.prix` — mais un écart signale
            // que le joueur n'a pas vu ce qu'il achète.
            if ($prixAttendu !== $annonce->getPrix()) {
                throw new HotelVenteIndisponibleException(
                    $this->normalizer->normalize($annonce, $user),
                    "Le prix de ce lot a changé."
                );
            }

            // Verrous des deux joueurs par id croissant : ordre déterministe anti-deadlock,
            // même patron qu'EchangeFinalisationService. Le refresh recharge l'or sous verrou,
            // les entités déjà en mémoire pouvant être périmées.
            $joueurs = [$user, $vendeur];
            usort($joueurs, fn (User $premier, User $second) => $premier->getId() <=> $second->getId());
            foreach ($joueurs as $joueur) {
                $this->entityManager->find(User::class, $joueur->getId(), LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($joueur);
            }

            $prix = $annonce->getPrix();

            // Débit avant crédit : l'acheteur paie avec l'or qu'il possède, jamais avec celui
            // qu'il est en train de recevoir (même raison qu'à l'échange).
            $this->sacService->debiterOr($user, $prix);
            $this->sacService->crediterOr($vendeur, $prix);
            $this->cumulJoueurService->ajouter($user, TypeCumul::OR_DEPENSE, $prix);
            $this->cumulJoueurService->ajouter($vendeur, TypeCumul::OR_GAGNE, $prix);

            // Le vendeur touche 100 % du prix : la commission a déjà été prélevée au dépôt.
            $this->sacService->ajouterItem($user, $annonce->getType(), $annonce->getItemId(), $annonce->getQuantite());

            // UNE ligne pour l'achat, pas quatre (item sorti, item entré, or débité, or
            // crédité) : le journal raconte un fait, et `SacService` garantit déjà les
            // mouvements d'inventaire. Acteur = acheteur, cible = vendeur : la même ligne
            // répond donc à « ce que X a acheté » et à « ce que Y a vendu ».
            $this->journalService->consigner(
                type: TypeEvenement::HDV_ACHAT,
                acteur: $user,
                cibleUser: $vendeur,
                montantOr: $prix,
                contexte: [
                    'annonceId' => $annonce->getId(),
                    'items' => $this->journalService->figerItems([[
                        'type' => $annonce->getType(),
                        'id' => $annonce->getItemId(),
                        'quantite' => $annonce->getQuantite(),
                    ]]),
                ],
            );

            $annonce->cloturer(StatutHotelVente::VENDUE, $user);
            $this->entityManager->flush();

            return [
                'annonce' => $this->normalizer->normalize($annonce, $user),
                'prix' => $prix,
            ];
        });

        return [
            'annonce' => $resultat['annonce'],
            'money' => (int) $user->getMoney(),
            'message' => sprintf(
                "%s ×%d acheté pour %d po.",
                $resultat['annonce']['item']['nom'],
                $resultat['annonce']['quantite'],
                $resultat['prix']
            ),
        ];
    }

    /**
     * Retire un lot invendu : l'objet revient au sac, les frais de dépôt restent perdus.
     *
     * @throws \DomainException lot inconnu, pas le sien, ou déjà clos
     */
    public function retirer(User $user, int $annonceId): array
    {
        $this->expirerAnnonce($annonceId);

        $annonce = $this->entityManager->wrapInTransaction(function () use ($user, $annonceId): HotelVente {
            $annonce = $this->annonceVerrouillee($annonceId);
            if ($annonce === null || $annonce->getVendeur()->getId() !== $user->getId()) {
                throw new \DomainException("Ce lot n'est pas le vôtre.");
            }
            if (!$annonce->estOuverte()) {
                throw new \DomainException("Ce lot n'est plus en vente.");
            }

            $this->entityManager->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);
            $this->sacService->ajouterItem($user, $annonce->getType(), $annonce->getItemId(), $annonce->getQuantite());

            $this->journalService->consigner(
                type: TypeEvenement::HDV_RETRAIT,
                acteur: $user,
                contexte: [
                    'annonceId' => $annonce->getId(),
                    'items' => $this->journalService->figerItems([[
                        'type' => $annonce->getType(),
                        'id' => $annonce->getItemId(),
                        'quantite' => $annonce->getQuantite(),
                    ]]),
                    // Jamais remboursés : c'est ce qui fait des frais un puits monétaire.
                    'fraisPerdus' => $annonce->getFraisDepot(),
                ],
            );

            $annonce->cloturer(StatutHotelVente::RETIREE);
            $this->entityManager->flush();

            return $annonce;
        });

        $normalisee = $this->normalizer->normalize($annonce, $user);

        return [
            'annonce' => $normalisee,
            'money' => (int) $user->getMoney(),
            'message' => sprintf(
                "%s ×%d retiré de la vente. Les %d po de frais de dépôt ne sont pas remboursés.",
                $normalisee['item']['nom'],
                $annonce->getQuantite(),
                $annonce->getFraisDepot()
            ),
        ];
    }

    /**
     * Clôt les annonces périmées et rend chaque invendu à son vendeur.
     *
     * ⚠️ Ce n'est PAS un simple filet comme l'expiration des échanges : c'est le seul chemin
     * par lequel un invendu revient à son propriétaire. Un lot que plus personne ne consulte
     * ne serait jamais restitué sans elle.
     *
     * Une transaction PAR annonce : l'échec d'une restitution ne doit pas empêcher les autres.
     */
    public function expirerVentesPerimees(): int
    {
        $expirees = 0;
        foreach ($this->hotelVenteRepository->findPerimees() as $annonce) {
            if ($this->expirerAnnonce($annonce->getId())) {
                ++$expirees;
            }
        }

        return $expirees;
    }

    /* ------------------------------------------------------------------ interne */

    /**
     * Clôt une annonce si elle est périmée, dans sa PROPRE transaction, et rend l'objet.
     * Rend `true` si l'expiration a bien eu lieu.
     */
    private function expirerAnnonce(int $annonceId): bool
    {
        try {
            return (bool) $this->entityManager->wrapInTransaction(function () use ($annonceId): bool {
                $annonce = $this->annonceVerrouillee($annonceId);
                if ($annonce === null || !$annonce->estOuverte() || !$annonce->estExpire()) {
                    return false;
                }

                $vendeur = $annonce->getVendeur();
                $this->entityManager->find(User::class, $vendeur->getId(), LockMode::PESSIMISTIC_WRITE);

                try {
                    $this->sacService->ajouterItem(
                        $vendeur,
                        $annonce->getType(),
                        $annonce->getItemId(),
                        $annonce->getQuantite()
                    );
                } catch (\DomainException $exception) {
                    // Le modèle d'item a disparu du contenu (pas de clé étrangère sur
                    // `item_id`) : il n'y a plus rien à rendre. On clôt quand même, sinon
                    // l'annonce resterait périmée pour toujours et la boucle d'expiration
                    // buterait dessus à chaque minute.
                    $this->logger->warning('Hôtel des ventes : restitution impossible', [
                        'annonce' => $annonceId,
                        'raison' => $exception->getMessage(),
                    ]);
                }

                // L'acteur est le VENDEUR et non le système : c'est son annonce et c'est
                // à lui que l'objet revient, donc la ligne doit apparaître sur sa fiche.
                $this->journalService->consigner(
                    type: TypeEvenement::HDV_EXPIRATION,
                    acteur: $vendeur,
                    contexte: [
                        'annonceId' => $annonce->getId(),
                        'items' => $this->journalService->figerItems([[
                            'type' => $annonce->getType(),
                            'id' => $annonce->getItemId(),
                            'quantite' => $annonce->getQuantite(),
                        ]]),
                    ],
                );

                $annonce->cloturer(StatutHotelVente::EXPIREE);
                $this->entityManager->flush();

                return true;
            });
        } catch (\Throwable $exception) {
            // Une annonce qui refuse de s'expirer ne doit pas emporter la boucle ni la requête
            // de l'acheteur qui a déclenché l'expiration paresseuse.
            $this->logger->error("Hôtel des ventes : échec de l'expiration", [
                'annonce' => $annonceId,
                'raison' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verrou pessimiste sur l'annonce, puis relecture.
     *
     * Le `refresh` n'est pas décoratif : `find()` peut rendre l'entité du cache d'identité,
     * c'est-à-dire l'état d'AVANT le verrou — exactement celui qu'un autre acheteur vient
     * peut-être de modifier. Même patron qu'EchangeService::echangeVerrouille().
     */
    private function annonceVerrouillee(int $annonceId): ?HotelVente
    {
        $annonce = $this->hotelVenteRepository->find($annonceId, LockMode::PESSIMISTIC_WRITE);
        if ($annonce === null) {
            return null;
        }
        $this->entityManager->refresh($annonce);

        return $annonce;
    }
}
