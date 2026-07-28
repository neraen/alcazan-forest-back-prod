<?php

namespace App\Controller;

use App\DTO\Spell\SpellIdDTO;
use App\DTO\Spell\SpellSlotDTO;
use App\Entity\UserConsommable;
use App\Entity\UserSortilege;
use App\Repository\BuffCaracteristiqueRepository;
use App\Repository\CaracteristiqueRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\ConsommableRepository;
use App\Repository\FriendRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\JoueurCaracteristiqueRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserBuffRepository;
use App\Repository\UserConsommableRepository;
use App\Repository\UserRepository;
use App\Repository\UserSortilegeRepository;
use App\Repository\WrapRepository;
use App\Exception\DonjonException;
use App\service\DonjonInstanceService;
use App\service\DonjonMapView;
use App\service\DonjonSalleService;
use App\service\KarmaService;
use App\service\MapService;
use App\service\WrapService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class JoueurController extends AbstractController
{

    public function __construct(){}


    #[Route("/joueur/disable/tutorial", name:"joueur_disable_tutorial", methods: ["POST"])]
    public function disableTutorial(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $user->setTutorialActive(false);
        $entityManager->persist($user);
        $entityManager->flush();
        $response = json_encode([]);
        return new Response($response, 200, ['Content-Type' => 'application/json']);
    }


    #[Route("/joueur/data/minimal", name:"joueur_data_minimal", methods: ["POST"])]
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
        // Le palier est calculé côté serveur : les seuils ne doivent exister qu'à un seul
        // endroit (ArtisanatConfig), sinon le libellé lu par le joueur et celui qui
        // conditionnera l'accès aux contenus finiraient par diverger.
        $minimalPlayerData['karma'] = KarmaService::decrireValeur((int)$minimalPlayerData['karma']);
        return new JsonResponse($minimalPlayerData);
    }


    /**
     * Sorts affichés sur la barre d'action. Tant que le joueur n'a rien assigné
     * (aucune ligne user_sortilege), tous les sorts appris sont renvoyés dans
     * l'ordre historique ; dès qu'il personnalise, seuls les sorts assignés
     * sont renvoyés, avec leur emplacement (`ordre`, 1-8).
     */
    #[Route("/joueur/spells", name:"joueur_spells", methods: ["POST"])]
    public function getPlayerSpells(
        SortilegeRepository        $sortilegeRepository,
        NiveauJoueurRepository     $niveauJoueurRepository,
    ): Response {
        $user = $this->getUser();
        $book = $this->getAllowedSpellBook($sortilegeRepository, $niveauJoueurRepository);

        $assigned = array_values(array_filter($book, fn($spell) => $spell['ordre'] !== null));
        if ($assigned !== []) {
            usort($assigned, fn($a, $b) => $a['ordre'] <=> $b['ordre']);
            return new JsonResponse($assigned);
        }

        return new JsonResponse(array_values($book));
    }

    /**
     * Grimoire complet du joueur (sorts appris de sa classe + emplacement assigné).
     */
    #[Route("/joueur/spells/book", name:"joueur_spells_book", methods: ["POST"])]
    public function getPlayerSpellBook(
        SortilegeRepository        $sortilegeRepository,
        NiveauJoueurRepository     $niveauJoueurRepository,
    ): Response {
        return new JsonResponse(array_values($this->getAllowedSpellBook($sortilegeRepository, $niveauJoueurRepository)));
    }

    /**
     * Place un sort appris sur un emplacement (1-8) de la barre de sorts.
     * À la première personnalisation, la barre actuelle (tous les sorts appris,
     * dans l'ordre) est matérialisée en base pour ne rien perdre. Si
     * l'emplacement cible est occupé : échange quand le sort déplacé avait déjà
     * un emplacement, remplacement sinon. Renvoie le grimoire à jour.
     */
    #[Route("/joueur/spell/equip", name:"joueur_spell_equip", methods: ["POST"])]
    public function equipSpell(
        #[MapRequestPayload] SpellSlotDTO $dto,
        SortilegeRepository               $sortilegeRepository,
        UserSortilegeRepository           $userSortilegeRepository,
        NiveauJoueurRepository            $niveauJoueurRepository,
        EntityManagerInterface            $entityManager
    ): Response {
        $user = $this->getUser();
        $spell = $this->findLearnedSpell($dto->spellId, $sortilegeRepository, $niveauJoueurRepository);
        if ($spell === null) {
            return new JsonResponse(['message' => "Vous ne connaissez pas ce sortilège."], 400);
        }

        $this->seedSpellBarIfEmpty($userSortilegeRepository, $sortilegeRepository, $niveauJoueurRepository, $entityManager);

        $current = $userSortilegeRepository->findOneBy(['user' => $user, 'sortilege' => $dto->spellId]);
        $occupant = $userSortilegeRepository->findOneBy(['user' => $user, 'ordre' => $dto->position]);

        if ($occupant && $occupant->getSortilege()->getId() !== $dto->spellId) {
            if ($current) {
                // Échange : l'occupant récupère l'ancien emplacement du sort déplacé.
                $occupant->setOrdre($current->getOrdre());
            } else {
                $entityManager->remove($occupant);
            }
        }

        if (!$current) {
            $current = new UserSortilege();
            $current->setUser($user);
            $current->setSortilege($entityManager->getReference(\App\Entity\Sortilege::class, $dto->spellId));
        }
        $current->setOrdre($dto->position);
        $entityManager->persist($current);
        $entityManager->flush();

        return new JsonResponse(array_values($this->getAllowedSpellBook($sortilegeRepository, $niveauJoueurRepository)));
    }

    /**
     * Retire un sort de la barre (il reste dans le grimoire). Renvoie le
     * grimoire à jour.
     */
    #[Route("/joueur/spell/unequip", name:"joueur_spell_unequip", methods: ["POST"])]
    public function unequipSpell(
        #[MapRequestPayload] SpellIdDTO $dto,
        SortilegeRepository             $sortilegeRepository,
        UserSortilegeRepository         $userSortilegeRepository,
        NiveauJoueurRepository          $niveauJoueurRepository,
        EntityManagerInterface          $entityManager
    ): Response {
        $user = $this->getUser();

        $this->seedSpellBarIfEmpty($userSortilegeRepository, $sortilegeRepository, $niveauJoueurRepository, $entityManager);

        $current = $userSortilegeRepository->findOneBy(['user' => $user, 'sortilege' => $dto->spellId]);
        if ($current) {
            $entityManager->remove($current);
            $entityManager->flush();
        }

        return new JsonResponse(array_values($this->getAllowedSpellBook($sortilegeRepository, $niveauJoueurRepository)));
    }

    /** Grimoire (classe + assignations) filtré par le niveau du joueur. */
    private function getAllowedSpellBook(
        SortilegeRepository    $sortilegeRepository,
        NiveauJoueurRepository $niveauJoueurRepository
    ): array {
        $user = $this->getUser();
        $book = $sortilegeRepository->getSpellBookWithOrder($user->getClasse()->getId(), $user->getId());
        $userLevel = $niveauJoueurRepository->getPlayerLevel($user->getId());

        return array_filter($book, fn($spell) => $spell['niveau'] <= $userLevel);
    }

    /** Le sort demandé, s'il appartient à la classe du joueur et est débloqué. */
    private function findLearnedSpell(
        int                    $spellId,
        SortilegeRepository    $sortilegeRepository,
        NiveauJoueurRepository $niveauJoueurRepository
    ): ?array {
        foreach ($this->getAllowedSpellBook($sortilegeRepository, $niveauJoueurRepository) as $spell) {
            if ($spell['id'] === $spellId) {
                return $spell;
            }
        }
        return null;
    }

    /**
     * Première personnalisation : matérialise la barre par défaut (tous les
     * sorts appris, emplacements 1..n) pour que l'assignation ne fasse pas
     * disparaître les autres sorts.
     */
    private function seedSpellBarIfEmpty(
        UserSortilegeRepository $userSortilegeRepository,
        SortilegeRepository     $sortilegeRepository,
        NiveauJoueurRepository  $niveauJoueurRepository,
        EntityManagerInterface  $entityManager
    ): void {
        $user = $this->getUser();
        if ($userSortilegeRepository->findBy(['user' => $user->getId()]) !== []) {
            return;
        }

        $ordre = 1;
        foreach ($this->getAllowedSpellBook($sortilegeRepository, $niveauJoueurRepository) as $spell) {
            if ($ordre > 8) {
                break;
            }
            $row = new UserSortilege();
            $row->setUser($user);
            $row->setSortilege($entityManager->getReference(\App\Entity\Sortilege::class, $spell['id']));
            $row->setOrdre($ordre);
            $entityManager->persist($row);
            $ordre++;
        }
        $entityManager->flush();
    }

    #[Route("/joueur/profil/spells", name:"joueur_profil_spells", methods: ["POST"])]
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

        return new JsonResponse($playerSpellsAllowed);
    }


    #[Route("/joueur/consommables", name:"joueur_consommables", methods: ["POST"])]
    public function getPlayerConsommables(UserConsommableRepository $userConsommableRepository): Response
    {
        $playerConsommables = $userConsommableRepository->getUserEquipedConsommable($this->getUser()->getId());
        return new JsonResponse($playerConsommables);
    }


    /**
     * Place un consommable de l'inventaire sur un des emplacements (1 ou 2) de la
     * barre de sorts. Upsert : l'emplacement cible reçoit le consommable ; toute
     * autre occurrence du même consommable est retirée (pas de doublon entre les
     * deux emplacements). Renvoie la liste équipée à jour pour rafraîchir la barre.
     */
    #[Route("/joueur/consommable/equip", name:"joueur_consommable_equip", methods: ["POST"])]
    public function equipConsommable(
        Request                         $request,
        ConsommableRepository           $consommableRepository,
        UserConsommableRepository       $userConsommableRepository,
        InventaireRepository            $inventaireRepository,
        InventaireConsommableRepository $inventaireConsommableRepository,
        EntityManagerInterface          $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $position = (int)($data['position'] ?? 0);
        $consommableId = $data['consommableId'] ?? null;

        if (!in_array($position, [1, 2], true) || $consommableId === null) {
            return new JsonResponse(['message' => 'Emplacement ou consommable invalide.'], 400);
        }

        $user = $this->getUser();
        $consommable = $consommableRepository->find($consommableId);
        if (!$consommable) {
            return new JsonResponse(['message' => 'Consommable introuvable.'], 404);
        }

        // Le joueur doit posséder ce consommable dans son inventaire.
        $inventaire = $inventaireRepository->findOneBy(['user' => $user->getId()]);
        $inventaireConsommable = $inventaire
            ? $inventaireConsommableRepository->findOneBy(['inventaire' => $inventaire->getId(), 'consommable' => $consommableId])
            : null;
        if (!$inventaireConsommable) {
            return new JsonResponse(['message' => "Vous ne possédez pas ce consommable."], 400);
        }

        // Dédoublonnage : retire ce consommable de tout autre emplacement.
        $existingSameConsommable = $userConsommableRepository->findBy(['user' => $user, 'consommable' => $consommable]);
        foreach ($existingSameConsommable as $existing) {
            if ($existing->getPosition() !== $position) {
                $entityManager->remove($existing);
            }
        }

        // Upsert sur l'emplacement cible.
        $slot = $userConsommableRepository->findOneBy(['user' => $user, 'position' => $position]);
        if (!$slot) {
            $slot = new UserConsommable();
            $slot->setUser($user);
            $slot->setPosition($position);
        }
        $slot->setConsommable($consommable);
        $slot->setQuantity($inventaireConsommable->getQuantity());
        $entityManager->persist($slot);
        $entityManager->flush();

        $playerConsommables = $userConsommableRepository->getUserEquipedConsommable($user->getId());
        return new JsonResponse($playerConsommables);
    }


    #[Route("/joueur/case/update_position", name:"update_case_position", methods: ["POST"])]
    public function updateCasePosition(
        Request                 $request,
        CarteCarreauRepository  $carteCarreauRepository,
        DonjonInstanceService   $donjonInstanceService,
        DonjonMapView           $donjonMapView,
        EntityManagerInterface  $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $mapId = $user->getMap()->getId();
        $returnMapInfo = [];

        $instance = $donjonInstanceService->instanceCourante($user);
        $enInstance = $instance !== null && $instance->contientCarte((int)$mapId);

        if($data['mapId'] == $mapId){
            $newCase = $carteCarreauRepository->findByCoordonnee($data['mapId'], $data['caseAbscisse'], $data['caseOrdonnee']);

            // En instance, `carte_carreau.joueur_id` n'est ni lu ni écrit (OneToOne global,
            // incompatible avec plusieurs groupes) : la collision se juge entre membres.
            $occupee = $enInstance
                ? $donjonMapView->positionOccupeeDansInstance(
                    $instance->getId(), (int)$mapId,
                    (int)$data['caseAbscisse'], (int)$data['caseOrdonnee'], $user->getId()
                )
                : $newCase[0]->getJoueur() !== null;

            if(!$occupee) {
                if(!$enInstance){
                    $carteCarreauRepository->updatePlayerInCase($user);
                }
                $user->setCaseAbscisse($data['caseAbscisse']);
                $user->setCaseOrdonnee($data['caseOrdonnee']);
                $mouvementPoint = $user->getMouvementPoint() - 1;
                $user->setMouvementPoint($mouvementPoint);
                $entityManager->persist($user);
                $entityManager->flush();
                if(!$enInstance){
                    $newCase[0]->setJoueur($user);
                    $entityManager->persist($newCase[0]);
                    $entityManager->flush();
                }
            }
        }

        $returnMapInfo['cases'] = $donjonMapView->casesPourJoueur($user, $mapId)['cases'];
        $returnMapInfo['mapId'] = $mapId;
        $returnMapInfo['life'] = $user->getCurrentLife();
        $returnMapInfo['mana'] = $user->getCurrentMana();
        $returnMapInfo['pm'] = $user->getMouvementPoint();
        $returnMapInfo['abscisseJoueur'] = $user->getCaseAbscisse();
        $returnMapInfo['ordonneeJoueur'] = $user->getCaseOrdonnee();

        return new JsonResponse($returnMapInfo);
    }

    #[Route("/joueur/map/update_position", name:"update_map_position", methods: ["POST"])]
    public function updateMapPosition(
        Request                 $request,
        WrapService             $wrapService,
        MapService              $mapService,
        CarteCarreauRepository  $carteCarreauRepository,
        WrapRepository          $wrapRepository,
        CarteRepository         $carteRepository,
        DonjonInstanceService   $donjonInstanceService,
        DonjonSalleService      $donjonSalleService,
        DonjonMapView           $donjonMapView,
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

        $donjonCible = $donjonInstanceService->donjonDeLaCarte((int)$data['targetMapId']);
        $instance = $donjonInstanceService->instanceCourante($user);

        // Circuler DANS son propre donjon (salle 1 → salle 2) n'est pas une entrée : c'est
        // un wrap ordinaire. Sans cette distinction on refaisait une entrée à chaque porte
        // interne — et une instance expirée bloquait le joueur à l'intérieur, incapable
        // même de rejoindre la sortie.
        $resteDansSonDonjon = $donjonCible !== null
            && $instance !== null
            && $instance->getDonjon()?->getId() === $donjonCible->getId();

        // Franchir une porte d'ENTRÉE = entrer dans une instance. Le verrou quotidien,
        // le niveau minimum et la taille de groupe sont arbitrés par DonjonInstanceService ;
        // un refus se présente au joueur comme un wrap bloqué.
        if($playerCanChangeMap['authorization'] && !is_null($donjonCible) && !$resteDansSonDonjon){
            try{
                $instance = $donjonInstanceService->entrer($user, $donjonCible);
            }catch(DonjonException $exception){
                $playerCanChangeMap = ['authorization' => false, 'message' => $exception->getMessage()];
            }
        }

        // Condition de salle (nettoyer, énigme, boss) : elle s'applique aussi bien à
        // l'entrée dans le donjon qu'aux passages internes. Une porte franchie le reste.
        if($playerCanChangeMap['authorization'] && !is_null($instance)){
            try{
                $donjonSalleService->verifierPassage($instance, (int)$data['targetMapId']);
            }catch(DonjonException $exception){
                $playerCanChangeMap = ['authorization' => false, 'message' => $exception->getMessage()];
            }
        }

        // Sortir d'un donjon vers le monde ouvert : on quitte l'instance (elle reste
        // acquise jusqu'au reset, on peut y revenir).
        if($playerCanChangeMap['authorization'] && is_null($donjonCible)){
            $donjonInstanceService->sortir($user);
            $instance = null;
        }

        if($playerCanChangeMap['authorization']){
            $newMap = $carteRepository->findOneBy(['id' => $data['targetMapId']]);

            $mapCases = $carteCarreauRepository->getAllCasesOfMap($data['targetMapId']);
            $newCaseId = $mapService->getPositionAfterMapChange($mapCases, $data['targetWrap']);
            $newCaseEntity = $carteCarreauRepository->find($newCaseId);

            $enInstance = $instance !== null;
            $occupee = $enInstance
                ? $donjonMapView->positionOccupeeDansInstance(
                    $instance->getId(), (int)$data['targetMapId'],
                    $newCaseEntity->getAbscisse(), $newCaseEntity->getOrdonnee(), $user->getId()
                )
                : $newCaseEntity->getJoueur() !== null;

            if(!$occupee) {
                // Libère toujours la case du monde ouvert qu'on quittait, y compris en
                // entrant en instance — sinon le joueur resterait « visible » dehors.
                $carteCarreauRepository->updatePlayerInCase($user);
                $user->setMap($newMap);
                $user->setCaseAbscisse($newCaseEntity->getAbscisse());
                $user->setCaseOrdonnee($newCaseEntity->getOrdonnee());
                $entityManager->persist($user);
                $entityManager->flush();
                if(!$enInstance){
                    $newCaseEntity->setJoueur($user);
                    $entityManager->persist($newCaseEntity);
                    $entityManager->flush();
                }
            }
            // La population de la salle n'apparaît qu'à la PREMIÈRE arrivée du groupe :
            // sinon un aller-retour la referait naître (ferme à XP).
            $annoncePopulation = is_null($instance)
                ? null
                : $donjonSalleService->peupler($instance, (int)$data['targetMapId']);

            $json = json_encode([
                'mapId' =>$data['targetMapId'],
                'ordonnee' => $newCaseEntity->getOrdonnee(),
                'abscisse' => $newCaseEntity->getAbscisse(),
                'instanceId' => $instance?->getId(),
                'annonce' => $annoncePopulation
            ]);
        }else{
            $json = json_encode([
                'message' => $playerCanChangeMap['message'],
            ]);
        }

        return new Response($json);
    }

    #[Route("/joueur/experience", name:"get_exp_joueur", methods: ["POST"])]
    public function getExpJoueur(NiveauJoueurRepository $niveauJoueurRepository): Response{
        $returnExpInfo = $niveauJoueurRepository->getNiveauAndExperience($this->getUser()->getId());
        return new JsonResponse($returnExpInfo);
    }

    #[Route("/joueur/caracteristiques", name:"get_caracteristiques_joueur", methods: ["GET", "POST"])]
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

        return new JsonResponse($caracteristiquesInfo);
    }

    #[Route("/joueur/caracteristiques/update", name:"update_caracteristiques_joueur", methods: ["POST"])]
    public function updateCaracteristiques(
        Request $request,
        JoueurCaracteristiqueRepository         $joueurCaracteristiqueRepository,
        JoueurCaracteristiqueBonusRepository    $joueurCaracteristiqueBonusRepository,
        CaracteristiqueRepository               $caracteristiqueRepository,
        NiveauJoueurRepository                  $niveauJoueurRepository,
        EntityManagerInterface                  $entityManager
    ): Response {
        $user = $this->getUser();
        $caracteristiques = json_decode($request->getContent());
        $levelJoueur = $niveauJoueurRepository->getPlayerLevel($user->getId());

        // Vérification serveur du plafond de points : niveau * 5 + 6 (anti-triche)
        $maxCaracsAllowed = ((int)$levelJoueur * 5) + 6;
        $totalPointsRequested = 0;
        foreach ($caracteristiques as $value){
            $totalPointsRequested += (int)$value;
        }
        if($totalPointsRequested > $maxCaracsAllowed){
            return new JsonResponse("Vous n'avez pas assez de points de caractéristiques disponibles", 400);
        }

        foreach ($caracteristiques as $name => $value){
            $caracteristiqueId = $caracteristiqueRepository->findOneBy(['nom' => $name])->getId();
            $joueurCaracteristiqueRepository->updateCaracteristique($user, $caracteristiqueId, $value);
            if($name === "constitution"){
                $caracteristiquesBonus = $joueurCaracteristiqueBonusRepository->findOneBy(['caracteristique' => $caracteristiqueId, 'joueur' => $user->getId()])->getPoints();
                $maxLife = 400 + (($value+$caracteristiquesBonus) * 5) + ((int)$levelJoueur * 8);
                $user->setMaxLife($maxLife);
                $entityManager->persist($user);
                $entityManager->flush();
            }
        }

        return new JsonResponse("Vos caractéristiques ont bien été mise à jour");
    }

    #[Route("/joueur/buffs", name:"joueur_buffs", methods: ["POST"])]
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
        return new JsonResponse($buffs);
    }


    #[Route("/joueur/data/profil", name:"joueur_data_profil", methods: ["POST"])]
    public function getDataJoueurForProfil(Request $request, UserRepository $userRepository): Response {
        $data = json_decode($request->getContent(), true);
        $pseudo = $data['pseudo'];

        $userProfilInfos = $userRepository->getDataForProfil($pseudo);

        return new JsonResponse($userProfilInfos);
    }

    #[Route("/joueur/isfriend", name:"joueur_get_is_friend", methods: ["POST"])]
    public function getIsFriend(Request $request, UserRepository $userRepository, FriendRepository $friendRepository): Response {
        $data = json_decode($request->getContent(), true);
        $dataUserId = $data['userId'];
        $user1 = $userRepository->find($dataUserId);
        $user2 = $this->getUser();

        $isFriend = $friendRepository->findOneBy(['user1' => $user1->getId(), 'user2' => $user2->getId()]);
        if(!$isFriend){
            $isFriend = $friendRepository->findOneBy(['user1' => $user2->getId(), 'user2' => $user1->getId()]);
        }

        return new JsonResponse(['friendId' => $isFriend?->getId() ?? 0]);
    }

//
//    #[Route("/joueur/spells", name:"joueur_spells", methods: ["POST"])]
//
//    public function getAllSpells(Request $request){
//        $user = $this->getUser();
//
//
//        return new JsonResponse([]);
//    }


}
