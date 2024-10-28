<?php

namespace App\Controller;

use App\Entity\InventaireConsommable;
use App\Entity\InventaireEquipement;
use App\Entity\UserEquipement;
use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\EquipementRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireObjetRepository;
use App\Repository\InventaireRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\UserEquipementRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class InventaireController extends AbstractController
{
    public function __construct(){}

    #[Route("/inventaire", name:"inventaire")]
    public function getPlayerInventaire(
        InventaireRepository                $inventaireRepository,
        InventaireEquipementRepository      $inventaireEquipementRepository,
        InventaireObjetRepository           $inventaireObjetRepository,
        InventaireConsommableRepository     $inventaireConsommableRepository,
        EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository
    ): Response {

        $userId = $this->getUser()->getId();
        $inventaire = $inventaireRepository->findOneBy(['user' => $userId]);
        $hasEquipement = !empty($inventaireEquipementRepository->findBy(["inventaire" => $inventaire]));
        $equipements = $hasEquipement ? $inventaireRepository->getPlayerEquipement($userId) : [];

        $hasObjet = !empty($inventaireObjetRepository->findBy(['inventaire' => $inventaire]));
        $objets = $hasObjet ? $inventaireRepository->getPlayerObjet($userId) : [];

        $hasConsommable = !empty($inventaireConsommableRepository->findBy(['inventaire' => $inventaire]));
        $consommables = $hasConsommable ? $inventaireRepository->getPlayerConsommable($userId) : [];

        foreach ($equipements as &$equipement){
            $caracterisitques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
            $equipement['caracteristiques'] = $caracterisitques;
        }

        $data = ['equipements' => $equipements, 'objets' => $objets, 'consommables' => $consommables];
        $json = json_encode($data);

        return new Response($json);
    }


    #[Route("/inventaire/equipement/equipe", name:"inventaire_equipement_equipe")]
    public function getPlayerEquipementEquipe(
        UserEquipementRepository            $userEquipementRepository,
        EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository
    ): Response {

        $userId = $this->getUser()->getId();
        $equipements = $userEquipementRepository->getPlayerEquipementEquipe($userId);

        foreach ($equipements as &$equipement){
            $caracterisitques = $equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
            $equipement['caracteristiques'] = $caracterisitques;
        }

        $json = json_encode($equipements);

        return new Response($json);
    }


    #[Route("/inventaire/equipement/unwear", name:"inventaire_equipement_unwear")]
    public function unequipEquipement(
        Request                                 $request,
        UserEquipementRepository                $userEquipementRepository,
        InventaireRepository                    $inventaireRepository,
        EquipementRepository                    $equipementRepository,
        InventaireEquipementRepository          $inventaireEquipementRepository,
        JoueurCaracteristiqueBonusRepository    $joueurCaracteristiqueBonusRepository,
        EntityManagerInterface                  $entityManager
    ): Response {
        $userId = $this->getUser()->getId();
        $data = json_decode($request->getContent(), true);

        $userEquipementEntity = $userEquipementRepository->findOneBy(['user' => $userId, 'equipement' => $data['idEquipement']]);
        $entityManager->remove($userEquipementEntity);
        $entityManager->flush();

        $inventaireEntity = $inventaireRepository->findOneBy(['user' => $userId]);
        $shouldIncrementExistingEquipement = $inventaireEquipementRepository->findOneBy(['inventaire' => $inventaireEntity->getId(), 'equipement' => $data['idEquipement']]);

        if($shouldIncrementExistingEquipement){
            $shouldIncrementExistingEquipement->setQuantity($shouldIncrementExistingEquipement->getQuantity() + 1);
            $entityManager->persist($shouldIncrementExistingEquipement);
            $entityManager->flush();
        }else{
            $inventaireEquipementEntity = new InventaireEquipement();
            $equipementEntity = $equipementRepository->findOneBy(['id' =>  $data['idEquipement']]);
            $inventaireEquipementEntity->setQuantity(1);
            $inventaireEquipementEntity->setEquipement($equipementEntity);
            $inventaireEquipementEntity->setInventaire($inventaireEntity);
            $entityManager->persist($inventaireEquipementEntity);
            $entityManager->flush();
        }

        $caracteristiques = $equipementEntity->getEquipementCaracteristiques();
        foreach ($caracteristiques as $caracteristique){
            $caracteristiqueId = $caracteristique->getCaracteristique()->getId();
            $joueurCaracteristiqueBonusEntity = $joueurCaracteristiqueBonusRepository->findOneBy(['caracteristique' => $caracteristiqueId, 'joueur' => $userId]);
            if($joueurCaracteristiqueBonusEntity){
                $valeurCaracteristique = $joueurCaracteristiqueBonusEntity->getPoints() - $caracteristique->getValeur() ;
                $joueurCaracteristiqueBonusEntity->setPoints($valeurCaracteristique);
                $entityManager->persist($joueurCaracteristiqueBonusEntity);
                $entityManager->flush();
            }

        }

        $json = json_encode([]);
        return new Response($json);
    }


    #[Route("/inventaire/equipement/wear", name:"inventaire_equipement_wear")]
    public function wearEquipement(
        Request                                 $request,
        UserEquipementRepository                $userEquipementRepository,
        InventaireRepository                    $inventaireRepository,
        EquipementRepository                    $equipementRepository,
        InventaireEquipementRepository          $inventaireEquipementRepository,
        JoueurCaracteristiqueBonusRepository    $joueurCaracteristiqueBonusRepository,
        EntityManagerInterface                  $entityManager
    ): Response {

        $userId = $this->getUser()->getId();
        $data = json_decode($request->getContent(), true);

        $equipementEntity = $equipementRepository->findOneBy(['id' =>  $data['idEquipement']]);
        $alreadyHaveEquipementInSamePosition = $userEquipementRepository->getEquipementEquipeByUserAndPosition($userId, $equipementEntity->getPositionEquipement()->getId());
        $inventaireEntity = $inventaireRepository->findOneBy(['user' => $userId]);

        if($alreadyHaveEquipementInSamePosition){
            $entityManager->remove($alreadyHaveEquipementInSamePosition);
            $entityManager->flush();

            $shouldIncrementExistingEquipement = $inventaireEquipementRepository->findOneBy(['inventaire' => $inventaireEntity->getId(), 'equipement' => $alreadyHaveEquipementInSamePosition->getEquipement()->getId()]);

            if($shouldIncrementExistingEquipement){
                $shouldIncrementExistingEquipement->setQuantity($shouldIncrementExistingEquipement->getQuantity() + 1);
                $entityManager->persist($shouldIncrementExistingEquipement);
                $entityManager->flush();
            } else {
                $inventaireEquipementEntity = new InventaireEquipement();
                $equipementEntity = $equipementRepository->findOneBy(['id' =>  $data['idEquipement']]);
                $inventaireEquipementEntity->setQuantity(1);
                $inventaireEquipementEntity->setEquipement($equipementEntity);
                $inventaireEquipementEntity->setInventaire($inventaireEntity);
                $entityManager->persist($inventaireEquipementEntity);
                $entityManager->flush();
            }
        }

        $inventaireEquipementToEquipEntity = $inventaireEquipementRepository->findOneBy(['inventaire' => $inventaireEntity->getId(), 'equipement' => $data['idEquipement']]);
        $quantity = $inventaireEquipementToEquipEntity->getQuantity();

        if($quantity === 1){
            $entityManager->remove($inventaireEquipementToEquipEntity);
            $entityManager->flush();
        }else{
            $inventaireEquipementToEquipEntity->setQuantity($quantity - 1);
            $entityManager->persist($inventaireEquipementToEquipEntity);
            $entityManager->flush();
        }

        $userEquipementEntity = new UserEquipement();
        $userEquipementEntity->setEquipement($equipementEntity);
        $userEquipementEntity->setUser($this->getUser());
        $entityManager->persist($userEquipementEntity);
        $entityManager->flush();

        $caracteristiques = $equipementEntity->getEquipementCaracteristiques();
        foreach ($caracteristiques as $caracteristique){
            $caracteristiqueId = $caracteristique->getCaracteristique()->getId();
            $joueurCaracteristiqueBonusEntity = $joueurCaracteristiqueBonusRepository->findOneBy(['caracteristique' => $caracteristiqueId, 'joueur' => $userId]);
            if($joueurCaracteristiqueBonusEntity){
                $valeurCaracteristique = $caracteristique->getValeur() + $joueurCaracteristiqueBonusEntity->getPoints();
                $joueurCaracteristiqueBonusEntity->setPoints($valeurCaracteristique);
                $entityManager->persist($joueurCaracteristiqueBonusEntity);
                $entityManager->flush();
            }

        }

        $json = json_encode([]);
        return new Response($json);
    }

}