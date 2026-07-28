<?php

namespace App\Tests\Service;

use App\Enum\CollectionImage;
use App\service\ImageUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Rangement des images uploadées depuis l'admin. Ce qui casse en silence ici, c'est la
 * frontière entre les deux conventions de stockage (avec / sans extension) : une valeur
 * rendue avec « .png » là où le front recolle déjà l'extension donne une image jamais
 * affichée, et personne ne s'en aperçoit avant de regarder la carte.
 */
class ImageUploaderTest extends TestCase
{
    private string $racine;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir() . '/alcazan-images-' . uniqid();
        mkdir($this->racine, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->supprimer($this->racine);
    }

    public function testUneCollectionAvecExtensionRendLeNomDeFichierComplet(): void
    {
        $valeur = $this->uploader()->upload($this->png(), CollectionImage::OBJET, 'Barre de fer');

        $this->assertSame('barre-de-fer.png', $valeur);
        $this->assertFileExists($this->racine . '/objet/barre-de-fer.png');
    }

    /** Le front construit `/img/monstre/<skin>.png` : stocker l'extension casserait l'URL. */
    public function testUneCollectionSansExtensionRendLeNomNu(): void
    {
        $valeur = $this->uploader()->upload($this->png(), CollectionImage::MONSTRE, 'Loup corrompu');

        $this->assertSame('loup-corrompu', $valeur);
        $this->assertFileExists($this->racine . '/monstre/loup-corrompu.png');
    }

    /** Avatar et skin d'un PNJ partagent le dossier : le suffixe évite un « -2 » illisible. */
    public function testAvatarEtSkinDUnMemePnjNeSeMarchentPasDessus(): void
    {
        $uploader = $this->uploader();

        $avatar = $uploader->upload($this->png(), CollectionImage::PNJ_AVATAR, 'Maître Eolan');
        $skin = $uploader->upload($this->png(), CollectionImage::PNJ_SKIN, 'Maître Eolan');

        $this->assertSame('maitre-eolan-avatar.png', $avatar);
        $this->assertSame('maitre-eolan-skin', $skin);
    }

    /** Deux objets homonymes : le second est suffixé plutôt que d'écraser le premier. */
    public function testUnHomonymeEstSuffixeAuLieuDEcraser(): void
    {
        $uploader = $this->uploader();
        $uploader->upload($this->png(), CollectionImage::OBJET, 'Bois');

        $this->assertSame('bois-2.png', $uploader->upload($this->png(), CollectionImage::OBJET, 'Bois'));
    }

    /** Ré-uploader l'image de l'élément édité écrase la sienne : pas de bois-2, -3, -4… */
    public function testReuploaderLaMemeValeurEcraseAuLieuDeSuffixer(): void
    {
        $uploader = $this->uploader();
        $uploader->upload($this->png(), CollectionImage::MONSTRE, 'Sanglier');

        $this->assertSame('sanglier', $uploader->upload($this->png(), CollectionImage::MONSTRE, 'Sanglier', 'sanglier'));
    }

    public function testUnJpegEstRefusePourUneCollectionSansExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->uploader()->upload($this->jpeg(), CollectionImage::INTERACTION, 'Champignon bleu');
    }

    public function testUnJpegEstAccepteQuandLExtensionEstStockee(): void
    {
        $this->assertSame(
            'bol-de-soupe.jpg',
            $this->uploader()->upload($this->jpeg(), CollectionImage::OBJET, 'Bol de soupe')
        );
    }

    public function testUnNomVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->uploader()->upload($this->png(), CollectionImage::OBJET, '   ');
    }

    /** Le nom sert à composer un chemin : « ../../ » ne doit pas sortir du dossier. */
    public function testUnNomTraversantEstReduitAUnSlug(): void
    {
        $valeur = $this->uploader()->upload($this->png(), CollectionImage::OBJET, '../../etc/passwd');

        $this->assertSame('etc-passwd.png', $valeur);
        $this->assertFileExists($this->racine . '/objet/etc-passwd.png');
    }

    public function testLUrlSuitLaConventionDeLaCollection(): void
    {
        $uploader = $this->uploader();

        $this->assertSame('/img/objet/bois.png', $uploader->url(CollectionImage::OBJET, 'bois.png'));
        $this->assertSame('/img/monstre/loup.png', $uploader->url(CollectionImage::MONSTRE, 'loup'));
        $this->assertSame('/img/pnj/dezelle-skin.png', $uploader->url(CollectionImage::PNJ_SKIN, 'dezelle-skin'));
    }

    private function uploader(): ImageUploader
    {
        return new ImageUploader(new AsciiSlugger(), $this->racine);
    }

    /** PNG 1x1 : `guessExtension()` renifle le contenu, un fichier bidon serait rejeté. */
    private function png(): UploadedFile
    {
        return $this->fichier(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ), 'image.png');
    }

    private function jpeg(): UploadedFile
    {
        return $this->fichier(base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        ), 'image.jpg');
    }

    private function fichier(string $contenu, string $nom): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($chemin, $contenu);

        // test: true => pas de contrôle d'upload HTTP, mais guessExtension() renifle bien.
        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function supprimer(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        foreach (scandir($chemin) as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $cible = $chemin . '/' . $entree;
            is_dir($cible) ? $this->supprimer($cible) : unlink($cible);
        }
        rmdir($chemin);
    }
}
