<?php

namespace App\Controller;

use App\Repository\BuffCaracteristiqueRepository;
use App\Repository\CaracteristiqueRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\FriendRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\JoueurCaracteristiqueRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserBuffRepository;
use App\Repository\UserConsommableRepository;
use App\Repository\UserRepository;
use App\Repository\UserSortilegeRepository;
use App\Repository\WrapRepository;
use App\service\MapService;
use App\service\WrapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class JoueurController extends AbstractController
{

    public function __construct(){}


    #[Route("/joueur/disable/tutorial", name:"joueur_disable_tutorial")]
    public function disableTutorial(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $user->setTutorialActive(false);
        $entityManager->persist($user);
        $entityManager->flush();
        $response = json_encode([]);
        return new Response($response, 200, ['Content-Type' => 'application/json']);
    }


    #[Route("/joueur/data/minimal", name:"joueur_data_minimal")]
    public function getMinimalPlayerData(
        UserRepository          $userRepository,
        NiveauJoueurRepository  $niveauJoueurRepository,
    ): Response
    {
        $minimalPlayerData = $userRepository->getMinimalPlayerData($this->getUser()->getId());
        $experienceAndLevel = $niveauJoueurRepository->getNiveauAndExperience($this->getUser()->getId());
        $minimalPlayerData['experienceActuelle'] = $experienceAndLevel['experienceActuelle'];
        $minimalPlayerData['experienceMax'] = $experienceAndLevel['experienceMax'];
        $minimalPlayerData['niveau'] = $experienceAndLevel['niveau'];
        $minimalPlayerDataResponse = json_encode($minimalPlayerData);

        return new Response($minimalPlayerDataResponse);
    }


    #[Route("/joueur/spells", name:"joueur_spells")]
    public function getPlayerSpells(
        SortilegeRepository        $sortilegeRepository,
        NiveauJoueurRepository     $niveauJoueurRepository,
    ): Response {
        $user = $this->getUser();
        //$playerSpellsOrdered = $userSortilegeRepository->findBy(['user' => $user->getId()]);

        //if($playerSpellsOrdered){
          //  $playerSpells = $playerSpellsOrdered;
        //}else{
            $playerSpells = $sortilegeRepository->getSpellsByClassId($user->getClasse()->getId());
        //}

        $userLevel = $niveauJoueurRepository->getPlayerLevel($user->getId());
        $playerSpellsAllowed = array_filter($playerSpells, function($spell) use ($userLevel){
            return $spell['niveau'] <= $userLevel;
        });

        $playerSpellsResponse = json_encode($playerSpellsAllowed);

        return new Response($playerSpellsResponse);
    }

    #[Route("/joueur/profil/spells", name:"joueur_profil_spells")]
    public function getPlayerProfilSpells(
        UserSortilegeRepository    $userSortilegeRepository,
        SortilegeRepository        $sortilegeRepository,
        NiveauJoueurRepository     $niveauJoueurRepository
    ): Response {
        $user = $this->getUser();
        $playerSpellsOrdered = $userSortilegeRepository->findBy(['user' => $user->getId()]);

        if($playerSpellsOrdered){
            $playerSpells = $playerSpellsOrdered;
            $playerSpells['isOrdered'] = true;
        }else{
            $playerSpells = $sortilegeRepository->getSpellsByClassId($this->getUser()->getClasse()->getId());
            $playerSpells['isOrdered'] = false;
        }

        $userLevel = $niveauJoueurRepository->getPlayerLevel($this->getUser()->getId());
        /**todo rajouter dans allowed le is ordered*/
        $playerSpellsAllowed = array_filter($playerSpells, function($spell) use ($userLevel){
            return $spell['niveau'] <= $userLevel;
        });

        $playerSpellsResponse = json_encode($playerSpellsAllowed);

        return new Response($playerSpellsResponse);
    }


    #[Route("/joueur/consommables", name:"joueur_consommables")]
    public function getPlayerConsommables(UserConsommableRepository $userConsommableRepository): Response
    {
        $playerConsommables = $userConsommableRepository->getUserEquipedConsommable($this->getUser()->getId());
        $playerConsommablesResponse = json_encode($playerConsommables);

        return new Response($playerConsommablesResponse);
    }


    #[Route("/joueur/case/update_position", name:"update_case_position")]
    public function updateCasePosition(
        Request                 $request,
        CarteCarreauRepository  $carteCarreauRepository,
        EntityManagerInterface  $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $mapId = $user->getMap()->getId();
        $returnMapInfo = [];
        if($data['mapId'] == $mapId){
            $newCase = $carteCarreauRepository->findByCoordonnee($data['mapId'], $data['caseAbscisse'], $data['caseOrdonnee']);

            if($newCase[0]->getJoueur() === null) {
                $carteCarreauRepository->updatePlayerInCase($user);
                $user->setCaseAbscisse($data['caseAbscisse']);
                $user->setCaseOrdonnee($data['caseOrdonnee']);
                $mouvementPoint = $user->getMouvementPoint() - 1;
                $user->setMouvementPoint($mouvementPoint);
                $entityManager->persist($user);
                $entityManager->flush();
                $newCase[0]->setJoueur($user);
                $entityManager->persist($newCase[0]);
                $entityManager->flush();
            }
        }

        $returnMapInfo['cases'] = $carteCarreauRepository->getAllCasesOfMap($mapId);
        $returnMapInfo['mapId'] = $mapId;
        $returnMapInfo['life'] = $user->getCurrentLife();
        $returnMapInfo['mana'] = $user->getCurrentMana();
        $returnMapInfo['pm'] = $user->getMouvementPoint();
        $returnMapInfo['abscisseJoueur'] = $user->getCaseAbscisse();
        $returnMapInfo['ordonneeJoueur'] = $user->getCaseOrdonnee();

        $json = json_encode($returnMapInfo);

        return new Response($json);
    }

    #[Route("/joueur/map/update_position", name:"update_map_position")]
    public function updateMapPosition(
        Request                 $request,
        WrapService             $wrapService,
        MapService              $mapService,
        CarteCarreauRepository  $carteCarreauRepository,
        WrapRepository          $wrapRepository,
        CarteRepository         $carteRepository,
        EntityManagerInterface  $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();

        $caseWrap = $carteCarreauRepository->find($data['wrapId']);
        $wrap = $wrapRepository->find($caseWrap->getWrap()->getId());

        if(!is_null($wrap->getMapCondition())){
            $playerCanChangeMap = $wrapService->canPlayerChangeMap($user, $wrap);
        }else{
            $playerCanChangeMap = ['authorization' => true];
        }


        if($playerCanChangeMap['authorization']){
            $newMap = $carteRepository->findOneBy(['id' => $data['targetMapId']]);

            $mapCases = $carteCarreauRepository->getAllCasesOfMap($data['targetMapId']);
            $newCaseId = $mapService->getPositionAfterMapChange($mapCases, $data['targetWrap']);
            $newCaseEntity = $carteCarreauRepository->find($newCaseId);


            if($newCaseEntity->getJoueur() === null) {
                $carteCarreauRepository->updatePlayerInCase($user);
                $user->setMap($newMap);
                $user->setCaseAbscisse($newCaseEntity->getAbscisse());
                $user->setCaseOrdonnee($newCaseEntity->getOrdonnee());
                $entityManager->persist($user);
                $entityManager->flush();
                $newCaseEntity->setJoueur($user);
                $entityManager->persist($newCaseEntity);
                $entityManager->flush();
            }
            $json = json_encode([
                'mapId' =>$data['targetMapId'],
                'ordonnee' => $newCaseEntity->getOrdonnee(),
                'abscisse' => $newCaseEntity->getAbscisse()
            ]);
        }else{
            $json = json_encode([
                'message' => $playerCanChangeMap['message'],
            ]);
        }

        return new Response($json);
    }

    #[Route("/joueur/experience", name:"get_exp_joueur")]
    public function getExpJoueur(NiveauJoueurRepository $niveauJoueurRepository): Response{
        $returnExpInfo = $niveauJoueurRepository->getNiveauAndExperience($this->getUser()->getId());
        $experienceJoueurData = json_encode($returnExpInfo);

        return new Response($experienceJoueurData);
    }

    #[Route("/joueur/caracteristiques", name:"get_caracteristiques_joueur")]
    public function getCaracteristiquesJoueur(
        JoueurCaracteristiqueRepository $joueurCaracteristiqueRepository,
        NiveauJoueurRepository          $niveauJoueurRepository
    ): Response{
        $userId = $this->getUser()->getId();

        $caracteristiquesInfo = [];
        $caracteristiques = $joueurCaracteristiqueRepository->getJoueurCaracteristiques($userId);
        $caracteristiquesInfo['caracteristiques'] = $caracteristiques;
        $levelJoueur = $niveauJoueurRepository->getPlayerLevel($userId);
        $maxCaracsAllowed = ($levelJoueur * 5) + 6;
        $caracteristiquesInfo['maxCaracsAllowed'] = $maxCaracsAllowed;

        $jsonCaracteristiques = json_encode($caracteristiquesInfo);

        return new Response($jsonCaracteristiques);
    }

    #[Route("/joueur/caracteristiques/update", name:"update_caracteristiques_joueur")]
    public function updateCaracteristiques(
        Request $request,
        JoueurCaracteristiqueRepository         $joueurCaracteristiqueRepository,
        JoueurCaracteristiqueBonusRepository    $joueurCaracteristiqueBonusRepository,
        CaracteristiqueRepository               $caracteristiqueRepository,
        NiveauJoueurRepository                  $niveauJoueurRepository,
        EntityManagerInterface                  $entityManager
    ): Response {
        $caracteristiques = json_decode($request->getContent());
        foreach ($caracteristiques as $name => $value){
            $caracteristiqueId = $caracteristiqueRepository->findOneBy(['nom' => $name])->getId();
            $joueurCaracteristiqueRepository->updateCaracteristique($this->getUser(), $caracteristiqueId, $value);
            if($name === "constitution"){
                $user = $this->getUser();
                $levelJoueur = $niveauJoueurRepository->getPlayerLevel($this->getUser()->getId());
                $caracteristiquesBonus = $joueurCaracteristiqueBonusRepository->findOneBy(['caracteristique' => $caracteristiqueId, 'joueur' => $user->getId()])->getPoints();
                $maxLife = 400 + (($value+$caracteristiquesBonus) * 5) + ((int)$levelJoueur * 8);
                $user->setMaxLife($maxLife);
                $entityManager->persist($user);
                $entityManager->flush();
            }
        }

        $newCaracteristiques = $joueurCaracteristiqueRepository->getJoueurCaracteristiques($user->getId());
        $newCaracteristiquesJson = json_encode("Vos caractéristiques ont bien été mise à jour");

        return new Response($newCaracteristiquesJson);
    }

    #[Route("/joueur/buffs", name:"joueur_buffs")]
    public function getActivePlayerBuff(
        UserBuffRepository              $userBuffRepository,
        BuffCaracteristiqueRepository   $buffCaracteristiqueRepository
    ): Response{
        $buffs = $userBuffRepository->getActivePlayerBuff($this->getUser()->getId());
        foreach ($buffs as &$buff){
           // $buff['dateDebut'] =
            if($buff['isCarac']){
                $caracteristiques = $buffCaracteristiqueRepository->getAllBuffCaracs($buff['id']);
                $buff['caracteristiques'] = $caracteristiques;
            }
        }
        return new Response(json_encode($buffs));
    }


    #[Route("/joueur/data/profil", name:"joueur_data_profil")]
    public function getDataJoueurForProfil(Request $request, UserRepository $userRepository): Response {
        $data = json_decode($request->getContent(), true);
        $pseudo = $data['pseudo'];

        $userProfilInfos = $userRepository->getDataForProfil($pseudo);

        return new Response(json_encode($userProfilInfos));
    }

    #[Route("/joueur/isfriend", name:"joueur_get_is_friend")]
    public function getIsFriend(Request $request, UserRepository $userRepository, FriendRepository $friendRepository): Response {
        $data = json_decode($request->getContent(), true);
        $dataUserId = $data['userId'];
        $user1 = $userRepository->find($dataUserId);
        $user2 = $this->getUser();

        $isFriend = $friendRepository->findOneBy(['user1' => $user1->getId(), 'user2' => $user2->getId()]);
        if(!$isFriend){
            $isFriend = $friendRepository->findOneBy(['user1' => $user2->getId(), 'user2' => $user1->getId()]);
        }

        return new Response(json_encode(['friendId' => $isFriend->getId() ?? 0]));
    }

//
//    #[Route("/joueur/spells", name:"joueur_spells")]
//
//    public function getAllSpells(Request $request){
//        $user = $this->getUser();
//
//
//        return new Response(json_encode([]));
//    }


}
