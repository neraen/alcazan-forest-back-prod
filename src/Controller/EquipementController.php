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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class EquipementController extends AbstractController
{
    public function __construct(){}

    #[Route("/equipement/create", name:"equipement_create")]
    public function createEquipement(
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

        $classeEntity = $classeRepository->find((int)$equipement['classe']);;
        $equipementEntity->addClasse($classeEntity);

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
        return new Response(json_encode($groupedEquipements ));
    }

    #[Route("/equipements/info", name:"all_equipements_grouped")]
    public function getAllEquipementsAndStats(EquipementRepository $equipementRepository, EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository){
        $equipements = $equipementRepository->getAllEquipementGroupedByPosition();
        foreach ($equipements as &$equipement){
            $caracteristiques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['id']);
            $equipement['caracteristiques'] = $caracteristiques;
        }
        return new Response(json_encode($equipements));
    }
}


