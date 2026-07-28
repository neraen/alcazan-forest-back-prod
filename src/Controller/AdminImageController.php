<?php

namespace App\Controller;

use App\Enum\CollectionImage;
use App\service\ImageUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Upload d'images depuis l'administration (métiers, objets, PNJ, monstres, cases
 * interactives). Le fichier est renommé d'après le nom de l'élément édité et rangé dans le
 * dossier de sa collection : l'admin n'a plus à déposer les images à la main.
 *
 * Les icônes d'équipement gardent leur route dédiée (`/api/equipement/upload-icone`) : elles
 * seules ont besoin d'un sous-dossier résolu depuis la position en base.
 */
#[Route("/api/admin/image", name: "api_admin_image_")]
class AdminImageController extends AbstractController
{
    #[Route("/upload", name: "upload", methods: ["POST"])]
    public function upload(Request $request, ImageUploader $imageUploader): Response
    {
        $collection = CollectionImage::tryFrom((string) $request->request->get('collection'));
        if ($collection === null || $collection->sousDossierRequis()) {
            return new JsonResponse(['error' => "Collection d'images inconnue."], Response::HTTP_BAD_REQUEST);
        }

        $file = $request->files->get('image');
        if ($file === null) {
            return new JsonResponse(
                ['error' => "Aucune image reçue (fichier trop lourd pour le serveur ?)."],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $fichier = $imageUploader->upload(
                $file,
                $collection,
                (string) $request->request->get('nom'),
                $request->request->get('valeurActuelle') ?: null
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // `fichier` est la valeur à enregistrer en base (avec ou sans extension selon la
        // collection), `url` celle à afficher : le front n'a pas à rejouer la règle.
        return new JsonResponse([
            'fichier' => $fichier,
            'url' => $imageUploader->url($collection, $fichier),
        ]);
    }
}
