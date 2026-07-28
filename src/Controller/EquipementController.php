<?php

namespace App\Controller;

use App\DTO\Equipement\CreateEquipementDTO;
use App\Entity\Equipement;
use App\Entity\EquipementCaracteristique;
use App\Repository\CaracteristiqueRepository;
use App\Repository\ClasseRepository;
use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\EquipementRepository;
use App\Repository\PositionEquipementRepository;
use App\Repository\RarityRepository;
use App\service\EquipementCsvImporter;
use App\service\EquipementIconeUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class EquipementController extends AbstractController
{
    public function __construct(){}

    #[Route("/equipement/create", name:"equipement_create", methods: ["POST"])]
    public function createEquipement(
        #[MapRequestPayload]
        CreateEquipementDTO                 $createEquipementDTO,
        EntityManagerInterface              $entityManager,
        CaracteristiqueRepository           $caracteristiqueRepository,
        PositionEquipementRepository        $positionEquipementRepository,
        EquipementRepository                $equipementRepository,
        EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository,
        RarityRepository                    $rarityRepository,
        ClasseRepository                    $classeRepository
    ): Response {
        $equipement = $createEquipementDTO->getEquipement();

        if($equipement['idEquipement']){
            $equipementEntity = $equipementRepository->find($equipement['idEquipement']);
        }else{
            $equipementEntity = new Equipement();
        }

        $equipementEntity->setNom($equipement['name']);
        $equipementEntity->setIcone($equipement['icone']);
        $equipementEntity->setDescription($equipement['description']);
        $equipementEntity->setPrixRevente($equipement['prixRevente']);
        $equipementEntity->setPrixAchat($equipement['prixAchat']);
        $equipementEntity->setLevelMin($equipement['levelMin']);

        $positionEquipementEntity = $positionEquipementRepository->find((int)$equipement['positionEquipement']);
        $equipementEntity->setPositionEquipement($positionEquipementEntity);

        $rarityEntity = $rarityRepository->find((int)$equipement['rarity']);
        $equipementEntity->setRarity($rarityEntity);

        $this->synchroniserClasses($equipementEntity, $equipement, $classeRepository);

        $entityManager->persist($equipementEntity);
        $entityManager->flush();


        foreach ($equipement['caracteristiques'] as $caracteristique){
            $caracteristiqueEntity = $caracteristiqueRepository->find($caracteristique['id']);
            $equipementCaracteristiqueExist = false;

            $equipementCaracteristiqueEntity = new EquipementCaracteristique();
            if($equipement['idEquipement']){
                $equipementCaracteristiqueEntity = $equipementCaracteristiqueRepository->findOneBy(['equipement' => $equipementEntity, "caracteristique" => $caracteristiqueEntity]);
                $equipementCaracteristiqueExist = $equipementCaracteristiqueEntity !== null;

                if(!$equipementCaracteristiqueExist){
                    $equipementCaracteristiqueEntity = new EquipementCaracteristique();
                }
            }

            if($caracteristique['valeur']){
                $equipementCaracteristiqueEntity->setEquipement($equipementEntity);
                $equipementCaracteristiqueEntity->setCaracteristique($caracteristiqueEntity);
                $equipementCaracteristiqueEntity->setValeur((int)$caracteristique['valeur']);
                $entityManager->persist($equipementCaracteristiqueEntity);
                $entityManager->flush();
            }else{
                if($equipementCaracteristiqueExist){
                    $entityManager->remove($equipementCaracteristiqueEntity);
                }
            }
        }

        $entityManager->flush();

        // L'id est renvoyé pour que le formulaire d'admin bascule en mode édition après une
        // création : sans lui, le clic suivant (ajout de l'image) créait un doublon.
        return new JsonResponse(['id' => $equipementEntity->getId()]);
    }

    /**
     * Aligne les classes autorisées d'un équipement sur la sélection du formulaire (relation
     * N-N `equipement_classe`). Une **liste vide = équipement toutes classes** : c'est la
     * convention du jeu, et elle reste juste si une classe est ajoutée plus tard.
     *
     * La collection est resynchronisée (retraits compris) : l'ancienne version se contentait
     * d'un `addClasse()`, si bien qu'éditer un équipement empilait les classes sans jamais
     * pouvoir en enlever une.
     *
     * @param array<string, mixed> $payload
     */
    private function synchroniserClasses(
        Equipement      $equipementEntity,
        array           $payload,
        ClasseRepository $classeRepository
    ): void {
        // `classes` (liste) est le format courant ; `classe` (scalaire) reste accepté pour
        // qu'un onglet d'admin resté ouvert sur l'ancienne version ne casse pas.
        $idsDemandes = $payload['classes'] ?? (isset($payload['classe']) ? [$payload['classe']] : []);
        $idsDemandes = array_values(array_unique(array_filter(array_map('intval', (array) $idsDemandes))));

        foreach ($equipementEntity->getClasse() as $classeExistante) {
            if (!in_array($classeExistante->getId(), $idsDemandes, true)) {
                $equipementEntity->removeClasse($classeExistante);
            }
        }

        foreach ($idsDemandes as $idClasse) {
            $classeEntity = $classeRepository->find($idClasse);
            if ($classeEntity !== null) {
                $equipementEntity->addClasse($classeEntity);
            }
        }
    }

    /**
     * Upload de l'icône d'un équipement depuis l'admin. Le fichier est renommé d'après le nom
     * saisi dans le formulaire et rangé dans le dossier de sa position ; le nom de fichier
     * renvoyé est celui à enregistrer dans `equipement.icone` (le front enchaîne sur /create).
     */
    #[Route("/equipement/upload-icone", name:"equipement_upload_icone", methods: ["POST"])]
    public function uploadIconeEquipement(
        Request                      $request,
        EquipementIconeUploader      $equipementIconeUploader,
        PositionEquipementRepository $positionEquipementRepository
    ): Response {
        $file = $request->files->get('icone');
        if ($file === null) {
            return new JsonResponse(['error' => "Aucune image reçue (fichier trop lourd pour le serveur ?)."], Response::HTTP_BAD_REQUEST);
        }

        $positionEquipement = $positionEquipementRepository->find((int) $request->request->get('positionEquipement'));
        if ($positionEquipement === null) {
            return new JsonResponse(['error' => "Position d'équipement inconnue."], Response::HTTP_BAD_REQUEST);
        }

        try {
            $icone = $equipementIconeUploader->upload(
                $file,
                (string) $request->request->get('name'),
                $positionEquipement->getName(),
                $request->request->get('currentIcone') ?: null
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'icone' => $icone,
            'position' => $positionEquipement->getName(),
        ]);
    }

    /**
     * Import en masse d'équipements depuis un CSV (admin). Renvoie un rapport ligne par ligne
     * plutôt qu'un simple OK/KO : sur 100 objets, l'utile est de savoir LESQUELS ont échoué.
     */
    #[Route("/equipement/import-csv", name:"equipement_import_csv", methods: ["POST"])]
    public function importCsvEquipement(
        Request                $request,
        EquipementCsvImporter  $equipementCsvImporter
    ): Response {
        $fichier = $request->files->get('csv');
        if ($fichier === null) {
            return new JsonResponse(['error' => "Aucun fichier reçu (fichier trop lourd pour le serveur ?)."], Response::HTTP_BAD_REQUEST);
        }

        // Par défaut on complète un équipement homonyme : c'est ce qui rend un CSV rejouable
        // après correction, sans semer de doublons.
        $mettreAJour = filter_var($request->request->get('mettreAJour', '1'), FILTER_VALIDATE_BOOL);

        try {
            $rapport = $equipementCsvImporter->importer($fichier, $mettreAJour);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($rapport);
    }

    #[Route("/equipement/formelements", name:"equipement_form_elements", methods: ["POST"])]
    public function getFormElementsEquipement(
        PositionEquipementRepository $positionEquipementRepository,
        RarityRepository             $rarityRepository,
        ClasseRepository             $classeRepository,
        CaracteristiqueRepository    $caracteristiqueRepository
    ): Response{

        $positionsEquipement = $positionEquipementRepository->findAllAssociative();
        $rarities = $rarityRepository->findAllAssociative();
        $classes = $classeRepository->findAllAssociative();
        $caracteristiques = $caracteristiqueRepository->findAllAssociative();

        $formElements = [
            'positions' => $positionsEquipement,
            'rarities' => $rarities,
            'classes' => $classes,
            'caracteristiques' => $caracteristiques
        ];



        return new JsonResponse($formElements);
    }

    #[Route("/equipements", name:"all_equipements", methods: ["POST"])]
    public function getAllEquipements(EquipementRepository $equipementRepository): Response {
        $equipements = $equipementRepository->findAll();
        $equipementsNormalized = [];

        foreach ($equipements as $equipement) {
            $equipementsNormalized[] = [
                'id' => $equipement->getId(),
                'name' => $equipement->getNom(),
                'icone' => $equipement->getIcone(),
            ];
        }

        return new JsonResponse([
            'equipements' => $equipementsNormalized
        ]);
    }


    #[Route("/equipements/grouped", name:"all_equipements_grouped", methods: ["POST"])]
    public function getAllEquipementsGrouped(EquipementRepository $equipementRepository){
        $groupedEquipements = $equipementRepository->getAllEquipementGroupedByPosition();
        return new JsonResponse($groupedEquipements );
    }

    #[Route("/equipements/info", name:"all_equipements_info", methods: ["POST"])]
    public function getAllEquipementsAndStats(EquipementRepository $equipementRepository, EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository){
        $equipements = $equipementRepository->getAllEquipementGroupedByPosition();
        $classesParEquipement = $equipementRepository->getClassesByEquipement();

        foreach ($equipements as &$equipement){
            $caracteristiques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['id']);
            $equipement['caracteristiques'] = $caracteristiques;
            // Tableau vide = aucune restriction, l'équipement est utilisable par toutes les classes.
            $equipement['classes'] = $classesParEquipement[$equipement['id']] ?? [];
        }
        return new JsonResponse($equipements);
    }
}


