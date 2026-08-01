<?php

namespace App\service;

use App\Entity\User;
use App\Enum\CategorieEvenement;
use App\Enum\TypeCumul;
use App\Enum\TypeEvenement;
use App\Repository\EvenementJeuRepository;
use App\Repository\UserRepository;

/**
 * Assemble les chiffres du tableau de bord d'administration.
 *
 * Service d'OBSERVATION : il ne mute rien, jamais. C'est ce qui le distingue d'un back-office
 * de modération — arbitrage précisé en §21.6 : observer est utile *précisément parce que* le
 * jeu n'a pas encore de population, modérer ne le sera qu'après.
 *
 * Il ne calcule aucun agrégat lui-même : il compose ceux des repositories et applique les
 * règles de DOMAINE que le SQL ne connaît pas — au premier rang desquelles la distinction
 * entre or créé, or détruit et or simplement transféré.
 */
class TableauDeBordService
{
    /** Fenêtre d'observation par défaut, en jours. */
    private const FENETRE_JOURS = 30;

    private const TAILLE_TOP = 10;

    /** Combien d'événements la fiche d'un joueur remonte. */
    private const EVENEMENTS_PAR_FICHE = 100;

    public function __construct(
        private readonly EvenementJeuRepository $evenementRepository,
        private readonly UserRepository $userRepository,
        private readonly JournalNormalizer $normalizer,
        private readonly CumulJoueurService $cumulJoueurService,
    ) {}

    public function tableauDeBord(): array
    {
        return [
            'fenetreJours' => self::FENETRE_JOURS,
            'activite' => $this->activite(),
            'economie' => $this->economie(),
            'topObjets' => $this->evenementRepository->topItems(
                [TypeEvenement::ECHANGE_CONCLU, TypeEvenement::HDV_ACHAT, TypeEvenement::VENTE_PNJ],
                self::FENETRE_JOURS,
                self::TAILLE_TOP
            ),
            'topVendeurs' => $this->evenementRepository->topActeurs(
                [TypeEvenement::HDV_ACHAT, TypeEvenement::VENTE_PNJ],
                self::FENETRE_JOURS,
                self::TAILLE_TOP
            ),
        ];
    }

    /**
     * Joueurs actifs et courbe d'activité par catégorie.
     *
     * La série est complétée jour par jour, y compris les jours SANS événement : une courbe
     * qui saute les jours vides ment sur la forme de l'activité — deux points espacés d'une
     * semaine se liraient comme deux jours consécutifs.
     */
    private function activite(): array
    {
        $parJour = $this->evenementRepository->compterParJour([], self::FENETRE_JOURS);

        $categories = [];
        foreach (CategorieEvenement::cases() as $categorie) {
            $categories[$categorie->value] = [];
        }

        $totaux = [];
        foreach ($parJour as $ligne) {
            $type = TypeEvenement::tryFrom($ligne['type']);
            if ($type === null) {
                continue;
            }
            $categorie = $type->categorie()->value;
            $totaux[$ligne['jour']][$categorie] = ($totaux[$ligne['jour']][$categorie] ?? 0) + $ligne['total'];
        }

        $jours = [];
        $curseur = new \DateTimeImmutable(sprintf('-%d days', self::FENETRE_JOURS - 1));
        for ($i = 0; $i < self::FENETRE_JOURS; ++$i) {
            $jour = $curseur->modify(sprintf('+%d days', $i))->format('Y-m-d');
            $jours[] = $jour;
            foreach ($categories as $cle => $_) {
                $categories[$cle][] = $totaux[$jour][$cle] ?? 0;
            }
        }

        return [
            'actifs24h' => $this->evenementRepository->joueursActifs(24),
            'actifs7j' => $this->evenementRepository->joueursActifs(24 * 7),
            'jours' => $jours,
            'series' => array_map(
                static fn (CategorieEvenement $categorie) => [
                    'cle' => $categorie->value,
                    'label' => $categorie->label(),
                    'points' => $categories[$categorie->value],
                    'total' => array_sum($categories[$categorie->value]),
                ],
                CategorieEvenement::cases()
            ),
        ];
    }

    /**
     * Masse monétaire : ce qui entre, ce qui sort, ce qui ne fait que changer de mains.
     *
     * L'écart création − destruction est LA question d'équilibrage d'un MMO : s'il est
     * durablement positif, l'or s'accumule et les prix dérivent. Les transferts sont donnés
     * à part, et ce n'est pas cosmétique — les additionner à la création ferait conclure à
     * une inflation qui n'existe pas.
     */
    private function economie(): array
    {
        $creation = $this->evenementRepository->orParType(
            TypeEvenement::parFlux('creation'),
            self::FENETRE_JOURS
        );
        $transfert = $this->evenementRepository->orParType(
            TypeEvenement::parFlux('transfert'),
            self::FENETRE_JOURS
        );

        // La destruction se lit à deux endroits : le montant des achats en échoppe, et les
        // FRAIS des dépôts — jamais leur `montant_or`, qui est le prix demandé.
        $achats = $this->evenementRepository->orParType([TypeEvenement::ACHAT_PNJ], self::FENETRE_JOURS);
        $frais = $this->evenementRepository->sommeFraisDepot(self::FENETRE_JOURS);

        $creee = array_sum($creation);
        $detruite = array_sum($achats) + $frais;

        return [
            'orCree' => $creee,
            'orDetruit' => $detruite,
            'solde' => $creee - $detruite,
            'orTransfere' => array_sum($transfert),
            'fraisDepot' => $frais,
            'detail' => [
                'creation' => $this->detailler($creation),
                'destruction' => $this->detailler($achats) + [
                    'Frais de dépôt (hôtel des ventes)' => $frais,
                ],
                'transfert' => $this->detailler($transfert),
            ],
        ];
    }

    /** @return array<string, int> libellé lisible => montant */
    private function detailler(array $parType): array
    {
        $detail = [];
        foreach ($parType as $valeur => $montant) {
            $type = TypeEvenement::tryFrom((string) $valeur);
            if ($type !== null && $montant > 0) {
                $detail[$type->label()] = $montant;
            }
        }

        return $detail;
    }

    /** La liste des joueurs pour le rail de l'écran, avec de quoi les distinguer. */
    public function joueurs(): array
    {
        return array_map(
            static fn (array $ligne) => [
                'id' => (int) $ligne['id'],
                'pseudo' => (string) $ligne['pseudo'],
                'niveau' => $ligne['niveau'] === null ? null : (int) $ligne['niveau'],
                'classe' => $ligne['classe'],
                'money' => (int) $ligne['money'],
                'derniereConnexion' => $ligne['lastConnexion'],
                'horsClassement' => (bool) $ligne['horsClassement'],
            ],
            $this->userRepository->listerPourAdministration()
        );
    }

    /**
     * La fiche d'enquête d'un joueur : qui il est, ses totaux, et ce qu'il a fait ET subi.
     *
     * « Et subi » est le point : c'est pour cette requête que `cible_user_id` est une colonne
     * indexée et non une clé du contexte JSON.
     */
    public function fiche(User $joueur): array
    {
        $evenements = $this->evenementRepository->rechercher(
            (int) $joueur->getId(),
            null,
            null,
            null,
            1,
            self::EVENEMENTS_PAR_FICHE
        );

        return [
            'joueur' => [
                'id' => (int) $joueur->getId(),
                'pseudo' => $joueur->getPseudo(),
                'email' => $joueur->getEmail(),
                'money' => (int) $joueur->getMoney(),
                'honneur' => (int) ($joueur->getHonneur() ?? 0),
                'karma' => $joueur->getKarma(),
                'horsClassement' => $joueur->isHorsClassement(),
                'creele' => $joueur->getCreated()?->format('Y-m-d H:i:s'),
                'derniereConnexion' => $joueur->getLastConnexion()?->format('Y-m-d H:i:s'),
            ],
            'cumuls' => $this->cumulJoueurService->decrire($joueur),
            'evenements' => $this->normalizer->normaliserPlusieurs($evenements['lignes']),
            'evenementsTotal' => $evenements['total'],
        ];
    }
}
