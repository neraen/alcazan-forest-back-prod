<?php

namespace App\service;

use App\Entity\Equipement;
use App\Entity\EquipementCaracteristique;
use App\Repository\CaracteristiqueRepository;
use App\Repository\ClasseRepository;
use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\EquipementRepository;
use App\Repository\PositionEquipementRepository;
use App\Repository\RarityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import en masse d'équipements depuis un CSV (admin). Pensé pour créer une centaine d'objets
 * d'un coup et venir leur accrocher les images ensuite : le rapport renvoie, pour chaque ligne,
 * le chemin d'image attendu (`img/equipement/<position>/<slug>.png`).
 *
 * Le contenu du fichier est la seule source : positions, raretés, classes et caractéristiques
 * sont résolues par NOM contre la base, donc ajouter une caractéristique au jeu suffit à la
 * rendre importable, sans toucher à ce service.
 */
class EquipementCsvImporter
{
    /** Garde-fou : un import d'admin, pas un pipeline de données. */
    public const MAX_LIGNES = 1000;

    private const MAX_SIZE = 2097152; // 2 Mo

    /**
     * Colonnes fixes : nom canonique => alias acceptés dans l'en-tête (déjà normalisés).
     * Toute colonne inconnue est comparée aux caractéristiques du jeu, puis ignorée.
     */
    private const COLONNES = [
        'nom'          => ['nom', 'name', 'intitule'],
        'description'  => ['description', 'desc'],
        'position'     => ['position', 'position_equipement', 'emplacement', 'slot'],
        'rarete'       => ['rarete', 'rarity'],
        'prix_achat'   => ['prix_achat', 'prixachat', 'achat'],
        'prix_revente' => ['prix_revente', 'prixrevente', 'revente', 'vente'],
        'level_min'    => ['level_min', 'levelmin', 'niveau_min', 'niveau', 'level'],
        'classes'      => ['classes', 'classe'],
        'icone'        => ['icone', 'icon', 'image'],
    ];

    /** Séparateurs candidats, du plus probable au moins probable sur un export tableur FR. */
    private const DELIMITEURS = [';', ',', "\t"];

    public function __construct(
        private readonly EntityManagerInterface              $entityManager,
        private readonly EquipementRepository                $equipementRepository,
        private readonly EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository,
        private readonly PositionEquipementRepository        $positionEquipementRepository,
        private readonly RarityRepository                    $rarityRepository,
        private readonly ClasseRepository                    $classeRepository,
        private readonly CaracteristiqueRepository           $caracteristiqueRepository,
        private readonly EquipementIconeUploader             $equipementIconeUploader
    ) {}

    /**
     * @param bool $mettreAJour un équipement portant déjà ce nom est complété (sinon la ligne
     *                          est refusée, pour ne pas écraser du contenu par inadvertance)
     *
     * @return array{crees: int, misAJour: int, ignores: int, colonnesIgnorees: list<string>,
     *               lignes: list<array{ligne: int, nom: string, statut: string, message: string, image: ?string}>}
     *
     * @throws \InvalidArgumentException fichier illisible, vide ou en-tête inexploitable
     */
    public function importer(UploadedFile $fichier, bool $mettreAJour = true): array
    {
        $flux = $this->ouvrirCsv($fichier);
        $delimiteur = $this->detecterDelimiteur($flux);

        $entete = fgetcsv($flux, 0, $delimiteur);
        if ($entete === false || $entete === [null]) {
            throw new \InvalidArgumentException('Fichier CSV vide.');
        }

        $referentiels = $this->chargerReferentiels();
        $colonnes = $this->mapperEntete($entete, $referentiels['caracteristiques'], $colonnesIgnorees);
        if (!isset($colonnes['nom'])) {
            throw new \InvalidArgumentException("Colonne « nom » introuvable dans l'en-tête du CSV.");
        }

        $rapport = ['crees' => 0, 'misAJour' => 0, 'ignores' => 0, 'colonnesIgnorees' => $colonnesIgnorees, 'lignes' => []];
        // Les noms déjà vus dans CE fichier : deux lignes homonymes doivent viser le même objet
        // plutôt que d'en créer deux (l'entité n'a pas de contrainte d'unicité sur le nom).
        $dejaVus = [];
        $numeroLigne = 1;

        $this->entityManager->beginTransaction();
        try {
            while (($valeurs = fgetcsv($flux, 0, $delimiteur)) !== false) {
                $numeroLigne++;

                if ($this->ligneVide($valeurs)) {
                    continue;
                }

                if (count($rapport['lignes']) >= self::MAX_LIGNES) {
                    throw new \InvalidArgumentException(
                        sprintf('Fichier trop long : %d lignes maximum par import.', self::MAX_LIGNES)
                    );
                }

                $rapport['lignes'][] = $this->importerLigne(
                    $numeroLigne, $valeurs, $colonnes, $referentiels, $mettreAJour, $dejaVus, $rapport
                );
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();
            throw $exception;
        } finally {
            fclose($flux);
        }

        return $rapport;
    }

    /**
     * Une ligne du fichier. Les erreurs sont rapportées, pas jetées : un nom mal orthographié
     * ne doit pas faire perdre les 99 autres lignes.
     *
     * @param array<int, string|null>                  $valeurs
     * @param array<string, int>                       $colonnes  nom canonique => index de colonne
     * @param array<string, mixed>                     $referentiels
     * @param array<string, Equipement>                $dejaVus
     * @param array{crees: int, misAJour: int, ignores: int} $rapport modifié au passage
     *
     * @return array{ligne: int, nom: string, statut: string, message: string, image: ?string}
     */
    private function importerLigne(
        int   $numeroLigne,
        array $valeurs,
        array $colonnes,
        array $referentiels,
        bool  $mettreAJour,
        array &$dejaVus,
        array &$rapport
    ): array {
        $nom = trim($this->cellule($valeurs, $colonnes, 'nom'));
        if ($nom === '') {
            $rapport['ignores']++;

            return $this->ligneRapport($numeroLigne, '', 'erreur', 'Nom manquant.');
        }

        $cle = mb_strtolower($nom);
        $equipement = $dejaVus[$cle] ?? $this->equipementRepository->findOneBy(['nom' => $nom]);
        $existait = $equipement !== null;

        if ($existait && !$mettreAJour) {
            $rapport['ignores']++;

            return $this->ligneRapport($numeroLigne, $nom, 'ignore', 'Un équipement porte déjà ce nom.');
        }

        $position = $this->resoudre($this->cellule($valeurs, $colonnes, 'position'), $referentiels['positions']);
        if ($position === null) {
            // La position est obligatoire (colonne NOT NULL) et détermine le dossier d'images :
            // impossible de deviner un défaut raisonnable.
            $rapport['ignores']++;

            return $this->ligneRapport($numeroLigne, $nom, 'erreur', sprintf(
                'Position inconnue « %s » (attendu : %s).',
                $this->cellule($valeurs, $colonnes, 'position'),
                implode(', ', $referentiels['positionsNoms'])
            ));
        }

        $rarete = $this->resoudre($this->cellule($valeurs, $colonnes, 'rarete'), $referentiels['rarites'])
            ?? $referentiels['rariteParDefaut'];
        if ($rarete === null) {
            $rapport['ignores']++;

            return $this->ligneRapport($numeroLigne, $nom, 'erreur', 'Rareté inconnue et aucune rareté par défaut en base.');
        }

        $equipement ??= new Equipement();
        $equipement->setNom($nom);
        $equipement->setDescription($this->cellule($valeurs, $colonnes, 'description') ?: null);
        $equipement->setPositionEquipement($position);
        $equipement->setRarity($rarete);
        $equipement->setPrixAchat($this->entier($this->cellule($valeurs, $colonnes, 'prix_achat')));
        $equipement->setPrixRevente($this->entier($this->cellule($valeurs, $colonnes, 'prix_revente')));
        $equipement->setLevelMin($this->entier($this->cellule($valeurs, $colonnes, 'level_min')));

        // Colonne `icone` volontairement facultative : le flux normal est « import CSV puis
        // upload des images dans l'EquipementMaker ». Sur une mise à jour, une cellule vide
        // conserve l'image déjà en place.
        $icone = trim($this->cellule($valeurs, $colonnes, 'icone'));
        if ($icone !== '' || !$existait) {
            $equipement->setIcone($icone);
        }

        $this->appliquerClasses($equipement, $this->cellule($valeurs, $colonnes, 'classes'), $referentiels['classes']);

        $this->entityManager->persist($equipement);
        // flush() avant les caractéristiques : elles ont besoin de l'id de l'équipement.
        $this->entityManager->flush();

        $this->appliquerCaracteristiques($equipement, $valeurs, $colonnes, $referentiels['caracteristiques']);

        $dejaVus[$cle] = $equipement;
        $existait ? $rapport['misAJour']++ : $rapport['crees']++;

        return $this->ligneRapport(
            $numeroLigne,
            $nom,
            $existait ? 'maj' : 'cree',
            $existait ? 'Équipement mis à jour.' : 'Équipement créé.',
            $equipement->getIcone()
                ? sprintf('img/equipement/%s/%s', $position->getName(), $equipement->getIcone())
                : sprintf('img/equipement/%s/%s.png', $position->getName(), $this->equipementIconeUploader->slugify($nom))
        );
    }

    /**
     * Aligne les caractéristiques sur les colonnes du fichier. Une valeur nulle ou vide efface
     * la ligne existante (même convention que le formulaire d'admin) ; une colonne absente du
     * fichier laisse la caractéristique inchangée.
     *
     * @param array<int, string|null>          $valeurs
     * @param array<string, int>               $colonnes
     * @param array<string, \App\Entity\Caracteristique> $caracteristiques nom normalisé => entité
     */
    private function appliquerCaracteristiques(Equipement $equipement, array $valeurs, array $colonnes, array $caracteristiques): void
    {
        foreach ($caracteristiques as $nomNormalise => $caracteristique) {
            if (!isset($colonnes[$nomNormalise])) {
                continue;
            }

            $valeur = $this->entier($this->cellule($valeurs, $colonnes, $nomNormalise));
            $ligne = $this->equipementCaracteristiqueRepository->findOneBy([
                'equipement'     => $equipement,
                'caracteristique' => $caracteristique,
            ]);

            if ($valeur === 0) {
                if ($ligne !== null) {
                    $this->entityManager->remove($ligne);
                }
                continue;
            }

            $ligne ??= new EquipementCaracteristique();
            $ligne->setEquipement($equipement);
            $ligne->setCaracteristique($caracteristique);
            $ligne->setValeur($valeur);
            $this->entityManager->persist($ligne);
        }
    }

    /**
     * Classes autorisées, séparées par `|`, `/` ou `,`. **Cellule vide = toutes classes**,
     * c'est la convention du jeu (relation N-N vide = aucune restriction).
     *
     * @param array<string, \App\Entity\Classe> $classesConnues
     */
    private function appliquerClasses(Equipement $equipement, string $cellule, array $classesConnues): void
    {
        $demandees = [];
        foreach (preg_split('/[|\/,]/', $cellule) ?: [] as $morceau) {
            $classe = $this->resoudre($morceau, $classesConnues);
            if ($classe !== null) {
                $demandees[$classe->getId()] = $classe;
            }
        }

        foreach ($equipement->getClasse() as $existante) {
            if (!isset($demandees[$existante->getId()])) {
                $equipement->removeClasse($existante);
            }
        }
        foreach ($demandees as $classe) {
            $equipement->addClasse($classe);
        }
    }

    /** Positions, raretés, classes et caractéristiques indexées par nom normalisé ET par id. */
    private function chargerReferentiels(): array
    {
        $positions = [];
        $positionsNoms = [];
        foreach ($this->positionEquipementRepository->findAll() as $position) {
            $positions[$this->normaliser($position->getName())] = $position;
            $positions[(string) $position->getId()] = $position;
            $positionsNoms[] = $position->getName();
        }

        $rarites = [];
        $rariteParDefaut = null;
        foreach ($this->rarityRepository->findAll() as $rarity) {
            $rarites[$this->normaliser($rarity->getName())] = $rarity;
            $rarites[(string) $rarity->getId()] = $rarity;
            $rariteParDefaut ??= $rarity;
        }

        $classes = [];
        foreach ($this->classeRepository->findAll() as $classe) {
            $classes[$this->normaliser($classe->getNom())] = $classe;
            $classes[(string) $classe->getId()] = $classe;
        }

        $caracteristiques = [];
        foreach ($this->caracteristiqueRepository->findAll() as $caracteristique) {
            $caracteristiques[$this->normaliser($caracteristique->getNom())] = $caracteristique;
        }

        return [
            'positions'       => $positions,
            'positionsNoms'   => $positionsNoms,
            'rarites'         => $rarites,
            'rariteParDefaut' => $rariteParDefaut,
            'classes'         => $classes,
            'caracteristiques' => $caracteristiques,
        ];
    }

    /**
     * @param array<int, string|null> $entete
     * @param array<string, mixed>    $caracteristiques
     * @param list<string>|null       $colonnesIgnorees rempli avec les en-têtes non reconnus
     *
     * @return array<string, int> nom canonique => index
     */
    private function mapperEntete(array $entete, array $caracteristiques, ?array &$colonnesIgnorees): array
    {
        $colonnes = [];
        $colonnesIgnorees = [];

        foreach ($entete as $index => $libelle) {
            $normalise = $this->normaliser((string) $libelle);
            if ($normalise === '') {
                continue;
            }

            $canonique = null;
            foreach (self::COLONNES as $nom => $alias) {
                if (in_array($normalise, $alias, true)) {
                    $canonique = $nom;
                    break;
                }
            }
            $canonique ??= isset($caracteristiques[$normalise]) ? $normalise : null;

            if ($canonique === null) {
                $colonnesIgnorees[] = trim((string) $libelle);
                continue;
            }

            // Premier gagnant : deux colonnes homonymes, on garde la plus à gauche.
            $colonnes[$canonique] ??= $index;
        }

        return $colonnes;
    }

    /** @param array<int, string|null> $valeurs */
    private function cellule(array $valeurs, array $colonnes, string $colonne): string
    {
        if (!isset($colonnes[$colonne])) {
            return '';
        }

        return trim((string) ($valeurs[$colonnes[$colonne]] ?? ''));
    }

    /**
     * @template T
     * @param array<string, T> $referentiel
     * @return T|null
     */
    private function resoudre(string $valeur, array $referentiel)
    {
        $cle = $this->normaliser($valeur);

        return $cle === '' ? null : ($referentiel[$cle] ?? null);
    }

    /** Accepte « 1 200 », « 1200.0 » ou vide ; tout le reste vaut 0. */
    private function entier(string $valeur): int
    {
        $valeur = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $valeur);

        return is_numeric($valeur) ? (int) round((float) $valeur) : 0;
    }

    /** "Vol de vie" / "VOL DE VIE" / "vol-de-vie" => "vol_de_vie". */
    private function normaliser(string $valeur): string
    {
        $valeur = trim($valeur);
        $translitere = @iconv('UTF-8', 'ASCII//TRANSLIT', $valeur);
        if ($translitere !== false) {
            $valeur = $translitere;
        }
        $valeur = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $valeur) ?? '');

        return trim($valeur, '_');
    }

    /** @param array<int, string|null> $valeurs */
    private function ligneVide(array $valeurs): bool
    {
        foreach ($valeurs as $valeur) {
            if (trim((string) $valeur) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array{ligne: int, nom: string, statut: string, message: string, image: ?string} */
    private function ligneRapport(int $ligne, string $nom, string $statut, string $message, ?string $image = null): array
    {
        return ['ligne' => $ligne, 'nom' => $nom, 'statut' => $statut, 'message' => $message, 'image' => $image];
    }

    /**
     * Charge le fichier en mémoire pour neutraliser deux plaies des exports tableur : le BOM
     * UTF-8 (qui collerait "\u{FEFF}" au premier en-tête) et les fichiers encodés en Windows-1252
     * (accents cassés). Le flux renvoyé est de l'UTF-8 propre.
     *
     * @return resource
     */
    private function ouvrirCsv(UploadedFile $fichier)
    {
        if ($fichier->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Fichier trop lourd (2 Mo maximum).');
        }

        $contenu = @file_get_contents($fichier->getPathname());
        if ($contenu === false || trim($contenu) === '') {
            throw new \InvalidArgumentException('Fichier CSV vide ou illisible.');
        }

        $contenu = preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $contenu) ?? $contenu;
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        $flux = fopen('php://temp', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        return $flux;
    }

    /**
     * Devine le séparateur sur la ligne d'en-tête : un export Excel français sort en `;`,
     * un export Google Sheets en `,`.
     *
     * @param resource $flux
     */
    private function detecterDelimiteur($flux): string
    {
        $entete = (string) fgets($flux);
        rewind($flux);

        $meilleur = self::DELIMITEURS[0];
        $occurrences = -1;
        foreach (self::DELIMITEURS as $delimiteur) {
            $compte = substr_count($entete, $delimiteur);
            if ($compte > $occurrences) {
                $occurrences = $compte;
                $meilleur = $delimiteur;
            }
        }

        return $meilleur;
    }
}
