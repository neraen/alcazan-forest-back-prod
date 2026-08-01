<?php

namespace App\service;

use App\Config\ArtisanatConfig;
use App\Entity\JoueurMetier;
use App\Entity\Metier;
use App\Entity\Objet;
use App\Entity\Pnj;
use App\Entity\User;
use App\Enum\FamilleMetier;
use App\Exception\MetierException;
use App\Repository\JoueurMetierRepository;
use App\Repository\MetierRepository;
use App\Repository\ObjetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNIQUE point de mutation des métiers d'un joueur : apprentissage, oubli, niveau et
 * expérience. Ne flushe pas — l'appelant fournit la transaction (même contrat que
 * SacService et RecompenseService).
 *
 * ⚠️ **Changement d'invariant du 26/07/2026.** Une ligne `joueur_metier` signifiait
 * « le joueur a déjà pratiqué ce métier » et se créait toute seule au premier gain
 * d'expérience. Elle signifie désormais « le joueur a APPRIS ce métier », et
 * `gagnerExperience()` REFUSE de la créer.
 *
 * Cette sévérité n'est pas gratuite : c'est elle qui rend applicable le plafond
 * « 2 métiers de récolte, 3 de fabrication ». Tant que la ligne naissait d'un effet de
 * bord, il n'y avait rien à plafonner — on aurait compté des métiers que le joueur
 * n'avait jamais choisis. Le corollaire est qu'un métier s'apprend chez un maître
 * (PNJ de type « metier ») et s'oublie au même endroit, en perdant sa progression.
 *
 * « Pas de ligne » veut donc dire « métier non appris », et le niveau rendu est 0 : les
 * interactions qui exigent « Mineur niveau 1 » refusent d'elles-mêmes un joueur qui n'a
 * jamais mis les pieds chez le maître mineur.
 */
class MetierService
{
    /**
     * Palier d'expérience du niveau N. Courbe volontairement simple et documentée comme
     * PLACEHOLDER, au même titre que les formules d'XP et d'honneur du jeu : 100 XP pour
     * le niveau 2, puis une progression quadratique douce.
     */
    public static function experiencePourNiveau(int $niveau): int
    {
        return $niveau <= 1 ? 0 : (int)(100 * ($niveau - 1) ** 1.5);
    }

    public function __construct(
        private readonly JoueurMetierRepository $joueurMetierRepository,
        private readonly MetierRepository $metierRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /* ------------------------------------------------------------------ */
    /* Lecture                                                             */
    /* ------------------------------------------------------------------ */

    /** Niveau du joueur dans ce métier ; 0 s'il ne l'a pas appris. */
    public function niveau(User $user, Metier $metier): int
    {
        return $this->progression($user, $metier)?->getNiveau() ?? 0;
    }

    public function estAppris(User $user, Metier $metier): bool
    {
        return $this->progression($user, $metier) !== null;
    }

    /**
     * Places restantes par famille.
     *
     * @return array<string, int> famille => nombre de métiers encore apprenables
     */
    public function placesRestantes(User $user): array
    {
        $appris = $this->comptesParFamille($user);
        $places = [];
        foreach (FamilleMetier::cases() as $famille) {
            $places[$famille->value] = max(0, ArtisanatConfig::plafond($famille) - ($appris[$famille->value] ?? 0));
        }

        return $places;
    }

    /**
     * Toute la progression du joueur, pour la fiche de personnage.
     *
     * `$avecRessources` ajoute, sur chaque métier de RÉCOLTE, le catalogue de ce qu'il
     * permet de ramasser. C'est une option et non le défaut parce que `CraftService`
     * n'appelle cette méthode que pour connaître les NIVEAUX du joueur : lui faire payer
     * une requête par métier de récolte pour un catalogue qu'il ne renvoie pas serait
     * du travail pur perdu.
     */
    public function progressionDe(User $user, bool $avecRessources = false): array
    {
        $metiers = array_map(
            fn (JoueurMetier $ligne) => $this->decrireProgression($ligne)
                + ($avecRessources ? ['ressources' => $this->ressourcesDe($ligne)] : []),
            $this->joueurMetierRepository->findBy(['user' => $user])
        );

        return [
            'metiers' => $metiers,
            'placesRestantes' => $this->placesRestantes($user),
            'plafonds' => ArtisanatConfig::plafonds(),
            'famillesLabels' => ArtisanatConfig::famillesLabels(),
        ];
    }

    /**
     * Vue d'un maître de métier : ce qu'il enseigne, et où en est le joueur pour chacun.
     *
     * Purement INFORMATIF, comme `InteractionService::decrire()` : ce qui est renvoyé
     * n'autorise rien, `apprendre()` revérifie tout.
     */
    public function vueMaitre(User $user, Pnj $pnj): array
    {
        $places = $this->placesRestantes($user);
        $enseignes = [];

        foreach ($pnj->getMetiers() as $metier) {
            $progression = $this->progression($user, $metier);
            $famille = $metier->getFamille();
            $placeLibre = ($places[$famille->value] ?? 0) > 0;

            $enseignes[] = [
                'id' => $metier->getId(),
                'nom' => $metier->getNom(),
                'description' => $metier->getDescription(),
                'icone' => $metier->getIcone(),
                'famille' => $famille->value,
                'familleLabel' => $famille->label(),
                'niveauMax' => $metier->getNiveauMax(),
                'appris' => $progression !== null,
                'niveau' => $progression?->getNiveau() ?? 0,
                'peutApprendre' => $progression === null && $placeLibre,
                'raison' => match (true) {
                    $progression !== null => "Vous exercez déjà ce métier.",
                    !$placeLibre => $this->messagePlafond($famille),
                    default => null,
                },
            ];
        }

        return [
            'metiers' => $enseignes,
            'placesRestantes' => $places,
            'plafonds' => ArtisanatConfig::plafonds(),
            'famillesLabels' => ArtisanatConfig::famillesLabels(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Apprentissage                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Apprend un métier. Refuse si déjà connu ou si la famille est pleine.
     *
     * @throws MetierException
     */
    public function apprendre(User $user, Metier $metier): array
    {
        if ($this->progression($user, $metier) !== null) {
            throw new MetierException("Vous exercez déjà le métier de {$metier->getNom()}.");
        }

        $famille = $metier->getFamille();
        $comptes = $this->comptesParFamille($user);
        if (($comptes[$famille->value] ?? 0) >= ArtisanatConfig::plafond($famille)) {
            throw new MetierException($this->messagePlafond($famille));
        }

        $progression = (new JoueurMetier())
            ->setUser($user)
            ->setMetier($metier)
            ->setNiveau(1)
            ->setExperience(0);

        $this->entityManager->persist($progression);

        return $this->decrireProgression($progression);
    }

    /**
     * Oublie un métier : la ligne disparaît, **la progression est perdue**. Sans cet
     * oubli, une erreur de choix enfermerait définitivement le personnage — la
     * confirmation est du ressort de l'appelant (front).
     *
     * @throws MetierException
     */
    public function oublier(User $user, Metier $metier): void
    {
        $progression = $this->progression($user, $metier);
        if ($progression === null) {
            throw new MetierException("Vous n'exercez pas le métier de {$metier->getNom()}.");
        }

        $this->entityManager->remove($progression);
    }

    /* ------------------------------------------------------------------ */
    /* Progression                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Crédite de l'expérience et fait monter de NIVEAU AUTANT DE FOIS que nécessaire
     * (un gain important ne doit pas être écrêté à un seul palier).
     *
     * Ne crée JAMAIS la ligne : de l'expérience gagnée sur un métier non appris est un
     * bug d'appelant (case de récolte mal configurée, recette sans garde-fou), pas un
     * apprentissage implicite.
     *
     * @throws MetierException si le métier n'est pas appris
     * @return array{niveau: int, experience: int, experienceProchainNiveau: int, niveauxGagnes: int}
     */
    public function gagnerExperience(User $user, Metier $metier, int $experience): array
    {
        $progression = $this->progression($user, $metier);
        if ($progression === null) {
            throw new MetierException("Il faut avoir appris le métier de {$metier->getNom()} auprès d'un maître.");
        }

        $niveauAvant = $progression->getNiveau();
        $progression->setExperience($progression->getExperience() + max(0, $experience));

        $plafond = $metier->getNiveauMax() > 0 ? $metier->getNiveauMax() : PHP_INT_MAX;
        while ($progression->getNiveau() < $plafond
            && $progression->getExperience() >= self::experiencePourNiveau($progression->getNiveau() + 1)) {
            $progression->setNiveau($progression->getNiveau() + 1);
        }

        $this->entityManager->persist($progression);

        return $this->decrireProgression($progression)
            + ['niveauxGagnes' => $progression->getNiveau() - $niveauAvant];
    }

    /** Métier de contenu par id. @throws MetierException */
    public function trouverMetier(int $metierId): Metier
    {
        $metier = $this->metierRepository->find($metierId);
        if ($metier === null) {
            throw new MetierException("Ce métier n'existe pas.");
        }

        return $metier;
    }

    /* ------------------------------------------------------------------ */
    /* Interne                                                             */
    /* ------------------------------------------------------------------ */

    private function progression(User $user, Metier $metier): ?JoueurMetier
    {
        return $this->joueurMetierRepository->findOneBy(['user' => $user, 'metier' => $metier]);
    }

    /** @return array<string, int> famille => nombre de métiers appris */
    private function comptesParFamille(User $user): array
    {
        $comptes = [];
        foreach ($this->joueurMetierRepository->findBy(['user' => $user]) as $ligne) {
            $famille = $ligne->getMetier()->getFamille()->value;
            $comptes[$famille] = ($comptes[$famille] ?? 0) + 1;
        }

        return $comptes;
    }

    private function messagePlafond(FamilleMetier $famille): string
    {
        $plafond = ArtisanatConfig::plafond($famille);
        $quoi = $famille === FamilleMetier::RECOLTE ? 'de récolte' : 'de fabrication';

        return "Vous ne pouvez pas exercer plus de {$plafond} métiers {$quoi}. "
            . "Oubliez-en un pour en apprendre un autre.";
    }

    /**
     * Ce qu'un métier de RÉCOLTE permet de ramasser : les objets classés ressources de ce
     * métier (`objet.metier`, l'onglet Ressources de l'ArtisanatMaker), du premier palier
     * au dernier.
     *
     * Les paliers HORS de portée sont renvoyés aussi, marqués `accessible: false` : une
     * liste amputée ne dirait pas au joueur ce que son prochain niveau lui ouvrira, et
     * c'est précisément ce qu'on vient lire sur la fiche d'un métier. Un métier de
     * fabrication n'a rien à ramasser — ses recettes viennent de `CraftService::atelier()`.
     *
     * Purement informatif : la récolte reste arbitrée par `InteractionService`, qui
     * revérifie métier et niveau sur la case.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ressourcesDe(JoueurMetier $ligne): array
    {
        $metier = $ligne->getMetier();
        if ($metier->getFamille() !== FamilleMetier::RECOLTE) {
            return [];
        }

        $niveau = $ligne->getNiveau();
        $ressources = $this->objetRepository->findBy(
            ['metier' => $metier],
            ['niveauRessource' => 'ASC', 'name' => 'ASC']
        );

        return array_map(fn (Objet $objet) => [
            'id' => $objet->getId(),
            'nom' => $objet->getName(),
            'description' => $objet->getDescription(),
            // Nom de fichier BRUT + type : les dossiers d'images vivent côté front
            // (`itemUtils.itemImage`), jamais dans une réponse du serveur.
            'type' => 'objet',
            'image' => $objet->getImage(),
            'niveauRequis' => $objet->getNiveauRessource(),
            'accessible' => $niveau >= $objet->getNiveauRessource(),
        ], $ressources);
    }

    private function decrireProgression(JoueurMetier $ligne): array
    {
        $metier = $ligne->getMetier();

        return [
            'metierId' => $metier->getId(),
            'nom' => $metier->getNom(),
            // La page Artisanat présente le métier, pas seulement sa barre : sans la
            // description, une fiche de métier n'a rien à dire.
            'description' => $metier->getDescription(),
            'icone' => $metier->getIcone(),
            'famille' => $metier->getFamille()->value,
            'familleLabel' => $metier->getFamille()->label(),
            'niveau' => $ligne->getNiveau(),
            'niveauMax' => $metier->getNiveauMax(),
            'experience' => $ligne->getExperience(),
            // Le front trace la barre entre ces deux bornes : sans le palier du niveau
            // courant, une barre calculée sur 0 → prochain palier repart en arrière à
            // chaque montée de niveau.
            'experienceNiveauCourant' => self::experiencePourNiveau($ligne->getNiveau()),
            'experienceProchainNiveau' => self::experiencePourNiveau($ligne->getNiveau() + 1),
        ];
    }
}
