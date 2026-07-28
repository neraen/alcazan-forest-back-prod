<?php

namespace App\service;

use App\Enum\CollectionImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Range les images uploadées depuis l'administration : le fichier est renommé d'après le nom
 * de l'élément édité ("Bouclier du pleutre" => "bouclier-du-pleutre.png") et déposé dans le
 * dossier de sa collection, là où le front va le chercher.
 *
 * C'est l'UNIQUE point d'écriture d'image de l'admin : équipements (via
 * `EquipementIconeUploader`, qui n'ajoute que la résolution du sous-dossier de position),
 * métiers, objets, PNJ, monstres et cases interactives passent tous par ici. Ne jamais
 * réécrire ailleurs dans `public/img` — la protection contre la traversée de répertoire, le
 * reniflage d'extension et la règle « PNG obligatoire quand la base ne stocke pas
 * l'extension » ne vivent qu'ici.
 *
 * Le dossier `public/img/<collection>` du back est bind-monté sur celui du front
 * (docker-compose.yaml) : l'image uploadée est servie immédiatement, sans copie manuelle.
 */
class ImageUploader
{
    /** Extensions autorisées, devinées d'après le contenu du fichier => extension sur le disque. */
    private const EXTENSIONS = [
        'png'  => 'png',
        'jpg'  => 'jpg',
        'jpeg' => 'jpg',
        'webp' => 'webp',
        'gif'  => 'gif',
    ];

    private const MAX_SIZE = 4194304; // 4 Mo

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly string $imagesDir
    ) {}

    /** "Épée du Culte de Rhog" => "epee-du-culte-de-rhog" */
    public function slugify(string $nom): string
    {
        return strtolower($this->slugger->slug(trim($nom))->toString());
    }

    /**
     * Déplace le fichier uploadé dans le dossier de la collection et renvoie la valeur à
     * stocker en base : nom de fichier complet, ou nom sans extension quand c'est le front qui
     * recolle `.png` (cf. `CollectionImage::avecExtension()`).
     *
     * @param string      $nom            nom de l'élément édité, source du nom de fichier
     * @param string|null $valeurActuelle valeur déjà enregistrée : si la cible porte le même
     *                                    nom, on écrase au lieu de suffixer
     * @param string|null $sousDossier    dossier intermédiaire (position d'un équipement)
     * @throws \InvalidArgumentException nom vide, sous-dossier manquant ou fichier refusé
     */
    public function upload(
        UploadedFile    $file,
        CollectionImage $collection,
        string          $nom,
        ?string         $valeurActuelle = null,
        ?string         $sousDossier = null
    ): string {
        $slug = $this->resolveSlug($collection, $nom);
        $extension = $this->resolveExtension($file, $collection);
        $directory = $this->resolveDirectory($collection, $sousDossier);

        $filename = $this->resolveFilename($directory, $slug, $extension, $this->fichierActuel($collection, $valeurActuelle));
        $file->move($directory, $filename);

        // move() crée le fichier en 0600 : le serveur de fichiers du front doit pouvoir le lire.
        @chmod($directory . '/' . $filename, 0644);

        return $collection->avecExtension() ? $filename : pathinfo($filename, PATHINFO_FILENAME);
    }

    /** URL publique d'une valeur stockée, telle que le front la construit. */
    public function url(CollectionImage $collection, string $valeur, ?string $sousDossier = null): string
    {
        $chemin = '/img/' . $collection->dossier();
        if ($sousDossier !== null && $sousDossier !== '') {
            $chemin .= '/' . $this->slugify($sousDossier);
        }

        return $chemin . '/' . $valeur . ($collection->avecExtension() ? '' : '.png');
    }

    /** Nom de fichier (slug + suffixe de collection) sous lequel l'image sera enregistrée. */
    private function resolveSlug(CollectionImage $collection, string $nom): string
    {
        $slug = $this->slugify($nom);
        if ($slug === '') {
            throw new \InvalidArgumentException("Nommez l'élément avant d'envoyer une image.");
        }

        $suffixe = $collection->suffixe();

        return $suffixe === null ? $slug : $slug . '-' . $suffixe;
    }

    private function resolveExtension(UploadedFile $file, CollectionImage $collection): string
    {
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Image trop lourde (4 Mo maximum).');
        }

        // guessExtension() renifle le contenu réel : un .png renommé en .exe est rejeté ici.
        $extension = strtolower((string) $file->guessExtension());
        if (!isset(self::EXTENSIONS[$extension])) {
            throw new \InvalidArgumentException('Format non supporté : utilisez PNG, JPG, WEBP ou GIF.');
        }

        $extension = self::EXTENSIONS[$extension];

        // La base ne garde que le nom : le front ira chercher "<nom>.png" et rien d'autre.
        // Accepter un JPEG ici produirait un fichier bien rangé mais une image jamais affichée.
        if (!$collection->avecExtension() && $extension !== 'png') {
            throw new \InvalidArgumentException(
                'Ce dossier n\'accepte que des PNG : le jeu construit l\'adresse de l\'image avec « .png ».'
            );
        }

        return $extension;
    }

    private function resolveDirectory(CollectionImage $collection, ?string $sousDossier): string
    {
        $directory = $this->imagesDir . '/' . $collection->dossier();

        if ($collection->sousDossierRequis() || ($sousDossier !== null && $sousDossier !== '')) {
            // Le sous-dossier vient de la base (nom d'une position) mais compose un chemin :
            // on le re-slugifie pour couper court à toute traversée de répertoire.
            $sousDossier = $this->slugify((string) $sousDossier);
            if ($sousDossier === '') {
                throw new \InvalidArgumentException('Sous-dossier d\'images inconnu.');
            }
            $directory .= '/' . $sousDossier;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException("Dossier d'images inaccessible : " . $directory);
        }

        return $directory;
    }

    /** Nom de fichier correspondant à la valeur déjà stockée (le `.png` implicite est recollé). */
    private function fichierActuel(CollectionImage $collection, ?string $valeurActuelle): ?string
    {
        if ($valeurActuelle === null || $valeurActuelle === '') {
            return null;
        }

        return $collection->avecExtension() ? $valeurActuelle : $valeurActuelle . '.png';
    }

    /** Évite d'écraser l'image d'un homonyme : bouclier-du-pleutre-2.png, -3… */
    private function resolveFilename(string $directory, string $slug, string $extension, ?string $fichierActuel): string
    {
        $filename = $slug . '.' . $extension;
        $suffixe = 2;

        while ($filename !== $fichierActuel && file_exists($directory . '/' . $filename)) {
            $filename = $slug . '-' . $suffixe . '.' . $extension;
            $suffixe++;
        }

        return $filename;
    }
}
