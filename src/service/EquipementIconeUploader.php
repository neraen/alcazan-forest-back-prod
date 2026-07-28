<?php

namespace App\service;

use App\Enum\CollectionImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Icônes d'équipement : le seul cas d'upload dont le fichier va dans un SOUS-DOSSIER calculé
 * (la position de l'équipement — "bras-droit", "tete"…), là où le front va le chercher
 * (`/img/equipement/<position>/<icone>`).
 *
 * Toute la mécanique (renommage, reniflage d'extension, anti-collision, anti-traversée) vit
 * dans `ImageUploader` et est partagée avec les autres images de l'admin : cette classe ne
 * fait qu'y accrocher la position. Elle reste le point d'entrée des équipements — l'import CSV
 * s'appuie sur `slugify()` pour annoncer le chemin d'image attendu.
 */
class EquipementIconeUploader
{
    public function __construct(
        private readonly ImageUploader $imageUploader
    ) {}

    /** "Épée du Culte de Rhog" => "epee-du-culte-de-rhog" */
    public function slugify(string $nom): string
    {
        return $this->imageUploader->slugify($nom);
    }

    /**
     * Déplace le fichier uploadé dans le dossier de la position et renvoie le nom de fichier
     * final, à stocker tel quel dans `equipement.icone`.
     *
     * @param string|null $currentIcone icône déjà rattachée à l'équipement édité : si le nom
     *                                  cible est le même, on écrase au lieu de suffixer.
     * @throws \InvalidArgumentException nom vide, position inconnue ou fichier refusé
     */
    public function upload(UploadedFile $file, string $nom, string $positionName, ?string $currentIcone = null): string
    {
        if ($this->slugify($nom) === '') {
            throw new \InvalidArgumentException("Nommez l'équipement avant d'envoyer une image.");
        }

        if ($this->slugify($positionName) === '') {
            throw new \InvalidArgumentException("Position d'équipement inconnue.");
        }

        return $this->imageUploader->upload($file, CollectionImage::EQUIPEMENT, $nom, $currentIcone, $positionName);
    }
}
