<?php

namespace App\Controller;

use App\Entity\Equipement;
use App\Entity\EquipementCaracteristique;
use App\Repository\CaracteristiqueRepository;
use App\Repository\ClasseRepository;
use App\Repository\EquipementRepository;
use App\Repository\PositionEquipementRepository;
use App\Repository\RarityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route("/api", name:"api_")]
class EquipementController extends AbstractController
{
    public function __construct(){}

    #[Route("/equipement/create", name:"equipement_create")]
    public function createEquipement(
        Request                             $request,
        EntityManagerInterface              $entityManager,
        CaracteristiqueRepository           $caracteristiqueRepository,
        PositionEquipementRepository        $positionEquipementRepository,
        RarityRepository                    $rarityRepository,
        ClasseRepository                    $classeRepository
    ): Response {
        $data = json_decode($request->getContent(), true);
        $equipement = $data['equipement'];

        $equipementEntity = new Equipement();
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

        $classeEntity = $classeRepository->find((int)$equipement['classe']);;
        $equipementEntity->addClasse($classeEntity);

        $entityManager->persist($equipementEntity);
        $entityManager->flush();

        foreach ($equipement['caracteristiques'] as $caracName => $caracValue){
            if((int)$caracValue > 0){
                $caracteristiqueEntity = $caracteristiqueRepository->findOneBy(['nom' => $caracName]);
                $equipementCaracteristiqueEntity = new EquipementCaracteristique();
                $equipementCaracteristiqueEntity->setEquipement($equipementEntity);
                $equipementCaracteristiqueEntity->setCaracteristique($caracteristiqueEntity);
                $equipementCaracteristiqueEntity->setValeur((int)$caracValue);
                $entityManager->persist($equipementCaracteristiqueEntity);
                $entityManager->flush();
            }
        }

        return new Response('');
    }

    #[Route("/equipement/formelements", name:"equipement_form_elements")]
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



        return new Response(json_encode($formElements));
    }

    #[Route("/equipements", name:"all_equipements")]
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

        return new Response(json_encode([
            'equipements' => $equipementsNormalized
        ]));
    }


    #[Route("/equipements/grouped", name:"all_equipements_grouped")]
    public function getAllEquipementsGrouped(EquipementRepository $equipementRepository){
        $groupedEquipements = $equipementRepository->getAllEquipementGroupedByPosition();
        dump($groupedEquipements);
        return new Response(json_encode($groupedEquipements ));
    }
}


