<?php

namespace App\Enum;

/**
 * Familles d'images uploadables depuis l'administration : une collection = un dossier de
 * `public/img` + la façon dont le nom de fichier est stocké en base.
 *
 * Deux conventions cohabitent dans le jeu et c'est le cœur du problème que cet enum règle :
 * certains champs stockent le nom de fichier COMPLET (`objet.image` => `/img/objet/bois.png`),
 * d'autres stockent le nom SANS extension parce que le front recolle `.png`
 * (`monstre.skin` => `/img/monstre/<skin>.png`). L'uploader doit donc savoir, par champ, ce
 * qu'il rend à l'appelant — et refuser un JPEG là où le front n'ira chercher qu'un PNG.
 *
 * Les valeurs voyagent dans le payload de `/api/admin/image/upload` : le front les reprend
 * telles quelles (cf. `administration/services/adminImageApi.js`).
 */
enum CollectionImage: string
{
    /** Icônes d'équipement : rangées dans un sous-dossier par position (`bras-droit`…). */
    case EQUIPEMENT = 'equipement';

    /** Icône d'un métier (ArtisanatMaker). */
    case METIER = 'metier';

    /** Image d'un objet / d'une ressource (`objet.image`). */
    case OBJET = 'objet';

    /** Portrait d'un PNJ affiché dans les dialogues (`pnj.avatar`, extension comprise). */
    case PNJ_AVATAR = 'pnj_avatar';

    /** Sprite d'un PNJ posé sur la carte (`pnj.skin`, sans extension). */
    case PNJ_SKIN = 'pnj_skin';

    /** Sprite d'un monstre (`monstre.skin`, sans extension). */
    case MONSTRE = 'monstre';

    /** Image d'une case interactive (`interaction.skin`, sans extension). */
    case INTERACTION = 'interaction';

    /** Dossier sous `public/img`. Avatar et skin de PNJ partagent volontairement le même. */
    public function dossier(): string
    {
        return match ($this) {
            self::EQUIPEMENT => 'equipement',
            self::METIER => 'metier',
            self::OBJET => 'objet',
            self::PNJ_AVATAR, self::PNJ_SKIN => 'pnj',
            self::MONSTRE => 'monstre',
            self::INTERACTION => 'interaction',
        };
    }

    /**
     * La valeur stockée en base porte-t-elle l'extension ?
     * `false` => le front construit l'URL en recollant `.png`, donc seuls les PNG sont acceptés.
     */
    public function avecExtension(): bool
    {
        return match ($this) {
            self::EQUIPEMENT, self::OBJET, self::PNJ_AVATAR => true,
            self::METIER, self::PNJ_SKIN, self::MONSTRE, self::INTERACTION => false,
        };
    }

    /**
     * Suffixe ajouté au slug du nom. Avatar et skin d'un même PNJ atterrissent dans le même
     * dossier : sans suffixe, le second upload se ferait renommer en `dezelle-2` — le fichier
     * serait bon mais le nom illisible. La convention existante du dossier est déjà
     * `<pnj>Avatar.png` / `<pnj>Skin.png`.
     */
    public function suffixe(): ?string
    {
        return match ($this) {
            self::PNJ_AVATAR => 'avatar',
            self::PNJ_SKIN => 'skin',
            default => null,
        };
    }

    /**
     * Collections dont le fichier va dans un sous-dossier calculé par l'appelant (la position,
     * pour un équipement). Elles ont leur propre endpoint : l'upload générique les refuse.
     */
    public function sousDossierRequis(): bool
    {
        return $this === self::EQUIPEMENT;
    }

    public function label(): string
    {
        return match ($this) {
            self::EQUIPEMENT => "Icône d'équipement",
            self::METIER => 'Icône de métier',
            self::OBJET => "Image d'objet",
            self::PNJ_AVATAR => 'Portrait de PNJ',
            self::PNJ_SKIN => 'Sprite de PNJ',
            self::MONSTRE => 'Sprite de monstre',
            self::INTERACTION => 'Image de case interactive',
        };
    }
}
