<?php

namespace App\Controller;

use App\Entity\Friend;
use App\Entity\InventaireEquipement;
use App\Entity\JoueurGuilde;
use App\Repository\BossRepository;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\FriendRepository;
use App\Repository\GuildeRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserRepository;
use App\service\DeathService;
use App\service\HistoriqueService;
use App\service\LevelingService;
use App\service\SpellService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;



#[Route("/api", name:"api_")]
class PlayerActionController extends AbstractController
{
    public function __construct(){}

    #[Route("/joueur/attack/joueur", name:"joueur_attack_joueur", methods: ["POST"])]
    public function attackPlayerVsPlayer(
        Request                 $request,
        UserRepository          $userRepository,
        SortilegeRepository     $sortilegeRepository,
        LevelingService         $levelingService,
        HistoriqueService       $historiqueService,
        SpellService            $spellService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $spell = $sortilegeRepository->find($data['spellId']);
        $arrayStat = [];
        $user = $this->getUser();

        $target = $userRepository->find($data['targetId']);

        $experience = 0;
        $message = "";
        $valueReturned = 0;
        if($spell->getType() === "attack"){

            $arrayStat['attack'] = $spellService->doDamage($target, $spell, $user);
            $experience =  mt_rand(180, 240);
            $message =  "Vous infligez {$arrayStat['attack']['damage']} points de dommages à {$target->getPseudo()} et vous gagnez $experience points d'expériences <br />";
            $message .=  isset($arrayStat['attack']['kill']) && $arrayStat['attack']['kill'] ? "Le joueur {$target->getPseudo()} meurt, vous gagnez {$arrayStat['attack']['honor']} points d'honneur." : "";
            $historiqueService->recordInHistoryPlayer($user, $message, false);
            $messageForPlayerTargeted = "{$user->getPseudo()} vous attaque avec {$spell->getNom()} et vous inflige {$arrayStat['attack']['damage']} points de dommages";
            $messageForPlayerTargeted .=  isset($arrayStat['attack']['kill']) && $arrayStat['attack']['kill'] ? "{$user->getPseudo()} vous a tué, vous perdez {$arrayStat['attack']['honorLoose']} points d'honneur." : "";
            $historiqueService->recordInHistoryPlayer($target, $messageForPlayerTargeted, true);
            $valueReturned = $arrayStat['attack']['damage'];

        }else if($spell->getType() === "soin"){

            $arrayStat['soin'] = $spellService->healPlayer($target, $spell, $user);
            $experience =  mt_rand(190, 255);
            $message =  "Vous soignez {$target->getPseudo()}, il récupère {$arrayStat['soin']['value']} points de vie et vous gagnez $experience points d'expériences <br />";
            $historiqueService->recordInHistoryPlayer($user, $message, false);
            $messageForPlayerTargeted = "{$user->getPseudo()} vous soigne et vous rend {$arrayStat['soin']['value']} points de vie";
            $historiqueService->recordInHistoryPlayer($target, $messageForPlayerTargeted, true);
            $valueReturned = $arrayStat['soin']['value'];

        }else if($spell->getType() === "buff"){
            $buffApplyed = $spellService->applyBuffEffect($target, $spell);
            $valueReturned = 0;
            $message = $buffApplyed
                ? "Vous utilisez {$spell->getNom()} sur {$target->getPseudo()}"
                : "{$target->getPseudo()} est déjà sous cet effet (ou a atteint la limite de 3 buffs)";
        }


        /* todo faire un calcul de l'attaque max potentielle pour l'experience */

        $newExperience = $levelingService->giveExperienceToAPlayer($experience, $user->getId());


        $json = json_encode([
            'damage' => $valueReturned,
            'experience' => $experience,
            'newExperience' => $newExperience['experience'],
            'lifeJoueur' => $arrayStat['lifeJoueur'] ?? $user->getCurrentLife(),
            'damageReturns' => $arrayStat['damageReturns'] ?? 0,
            'droppedItems' => $droppedItems ?? [],
            'mapId' => $mapId ?? $user->getMap()->getId(),
            'level' => $newExperience['level'],
            'message' => $message,
            'pa' => $user->getActionPoint(),
            'needRefresh' => isset($arrayStat['attack']['kill'])
        ]);

        return new Response($json);
    }


    #[Route("/joueur/attack/monster", name:"joueur_attack_monster", methods: ["POST"])]
    public function attackPlayerVsMonster(
        Request                     $request,
        SortilegeRepository         $sortilegeRepository,
        MonstreCarreauRepository    $monstreCarreauRepository,
        SpellService                $spellService,
        DeathService                $deathService,
        HistoriqueService           $historiqueService,
        LevelingService             $levelingService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $spell = $sortilegeRepository->find($data['spellId']);
        $user = $this->getUser();

        $target = $monstreCarreauRepository->find($data['targetId']);
        $arrayStat = $spellService->doDamageOnMonster($target, $spell, $user);
        $droppedItems = [];

        if($arrayStat['life'] > 0){
            $monstreCarreauRepository->doDamage($target, $arrayStat['life']);
        }else{
            $droppedItems = $deathService->dieMonster($target, $user);
        }

        $experience =  mt_rand(180, 240);
        $newExperience = $levelingService->giveExperienceToAPlayer($experience, $user->getId());

        $isDead = false;
        if((int)$arrayStat['lifeJoueur'] <= 0){
            $statsAfterDeath = $deathService->diePlayer($user);
            $message = "Le monstre {$target->getMonstre()->getName()} vous a infligé {$arrayStat['damage']} et vous a tué.";
            $historiqueService->recordInHistoryPlayer($user, $message, true);
            $newExperience['experience'] = $statsAfterDeath['experience'];
            $mapId = $statsAfterDeath['mapId'];
            $isDead = true;
        }

        $message =  "Vous infligez {$arrayStat['damage']} points de dommages et vous gagnez $experience points d'expériences <br />
                     Le monstre riposte et vous inflige {$arrayStat['damageReturns']} points de dommage <br />";

        $message .= isset($droppedItems[0]) ? "<span>En mourrant le monstre laisse tomber ceci : <strong>{$droppedItems[0]}</strong></span> <br />" : "";
        $message .= $isDead ? "<strong> Vous êtes mort suite aux blessures infligées par le monstre. </strong>" : "";

        $json = json_encode([
            'damage' => $arrayStat['damage'],
            'experience' => $experience,
            'newExperience' => $newExperience['experience'],
            'lifeJoueur' => $arrayStat['lifeJoueur'] ?? $user->getCurrentLife(),
            'damageReturns' => $arrayStat['damageReturns'] ?? 0,
            'droppedItems' => $droppedItems ?? [],
            'mapId' => $mapId ?? $user->getMap()->getId(),
            'level' => $newExperience['level'],
            'pa' => $user->getActionPoint(),
            'message' => $message,
        ]);

        return new Response($json);
    }


    #[Route("/joueur/attack/boss", name:"joueur_attack_boss", methods: ["POST"])]
    public function attackPlayerVsBoss(
        Request                 $request,
        SortilegeRepository     $sortilegeRepository,
        BossRepository          $bossRepository,
        SpellService            $spellService,
        DeathService            $deathService,
        HistoriqueService       $historiqueService,
        LevelingService         $levelingService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $spell = $sortilegeRepository->find($data['spellId']);
        $arrayStat = [];
        $user = $this->getUser();

        $target = $bossRepository->find($data['targetId']);

        $experience = 0;
        if($spell->getType() === "attack"){
            $arrayStat = $spellService->doDamageOnBoss($target, $spell, $user);
            $experience =  mt_rand(235, 340);
        }else{
            /** coder les buffs */
        }


        /* todo faire un calcul de l'attaque max potentielle pour l'experience */

        $newExperience = $levelingService->giveExperienceToAPlayer($experience, $user->getId());

        $isDead = false;
        if((int)$arrayStat['lifeJoueur'] <= 0){
            $statsAfterDeath = $deathService->diePlayer($user);
            $message = "Le boss {$target->getName()} vous a infligé {$arrayStat['damage']} et vous a tué.";
            $historiqueService->recordInHistoryPlayer($user, $message, true);
            $newExperience['experience'] = $statsAfterDeath['experience'];
            $mapId = $statsAfterDeath['mapId'];
            $isDead = true;
        }

        $message = "Vous infligez {$arrayStat['damage']} points de dommages et vous gagnez $experience points d'expériences <br />
                     {$target->getName()} vous attaque avec {$arrayStat['spell']} et vous inflige {$arrayStat['damageReturns']} points de dommage !<br />";

        $message .= !is_null($arrayStat['killMessage']) ? $arrayStat['killMessage']. " <br />" : "";
        $message .= $isDead ? "<strong> Vous êtes mort suite aux blessures infligées par {$target->getName()}. </strong>" : "";



        $json = json_encode([
            'damage' => $arrayStat['damage'] ?? 0,
            'experience' => $experience,
            'newExperience' => $newExperience['experience'],
            'lifeJoueur' => $arrayStat['lifeJoueur'] ?? $user->getCurrentLife(),
            'damageReturns' => $arrayStat['damageReturns'] ?? 0,
            'droppedItems' => $droppedItems ?? [],
            'mapId' => $mapId ?? $user->getMap()->getId(),
            'level' => $newExperience['level'],
            'message' => $message,
            'pa' => $user->getActionPoint(),
            'needRefresh' => isset($arrayStat['kill'])
        ]);

        return new Response($json);
    }


    #[Route("/joueur/spell/self", name:"joueur_spell_self", methods: ["POST"])]
    public function useSpellAutoFocused(
        Request             $request,
        SortilegeRepository $sortilegeRepository,
        SpellService        $spellService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $spell = $sortilegeRepository->find($data['spellId']);
        $arrayStat = [];
        $user = $this->getUser();

        $message = "";
        if($spell->getType() === "soin"){
            $arrayStat = $spellService->healPlayer($user, $spell, $user);
            $message =  "Vous vous soignez avec {$spell->getNom()}, vous récupèrez {$arrayStat['value']} points de vie <br />";

        }else if ($spell->getType() === "buff"){
            $buffApplyed = $spellService->applyBuffEffect($user, $spell);
            $message = $buffApplyed
                ? "Vous êtes maintenant sous les effets de {$spell->getNom()} <br />"
                : "Vous êtes déjà sous cet effet (ou avez atteint la limite de 3 buffs) <br />";
        }

        return new JsonResponse([
            'message' => $message,
            'life' => $arrayStat['life'] ?? $user->getCurrentLife(),
            'needRefresh' => true
        ]);
    }


    #[Route("/joueur/use/consommable", name:"joueur_use_consommable", methods: ["POST"])]
    public function useConsommable(
        Request                         $request,
        InventaireRepository            $inventaireRepository,
        InventaireConsommableRepository $inventaireConsommableRepository,
        ConsommableRepository           $consommableRepository,
        EntityManagerInterface          $entityManager,
    ): Response {
        $dataConsommable = json_decode($request->getContent(), true);
        $consommableEntity = $consommableRepository->find($dataConsommable['consommableId']);
        $user = $this->getUser();

        $inventaireEntity = $inventaireRepository->findOneBy(['user' => $user->getId()]);
        $inventaireConsommable = $inventaireConsommableRepository->findOneBy(
            ['inventaire' => $inventaireEntity->getId(), 'consommable' =>$dataConsommable['consommableId']]);

        $message = '';
        $quantity = $inventaireConsommable->getQuantity();
        if($quantity > 0){

            $inventaireConsommable->setQuantity($quantity-1);
            $entityManager->persist($user);
            $entityManager->flush();

            $typeConsommable = $consommableEntity->getType();
            if(!$consommableEntity->getIsBuff()){
                if($typeConsommable === "vie"){
                    $userLifeAfterUse = $user->getCurrentLife() + $consommableEntity->getPoints();
                    if($userLifeAfterUse > $user->getMaxLife()){
                        $userLifeAfterUse = $user->getMaxLife();
                    }

                    $user->setCurrentlife($userLifeAfterUse);
                    $entityManager->persist($user);

                }elseif ($typeConsommable === "mana"){
                    $userManaAfterUse = $user->getCurrentMana() + $consommableEntity->getPoints();
                    if($userManaAfterUse > $user->getMaxMana()){
                        $userManaAfterUse = $user->getMaxMana();
                    }

                    $user->setCurrentMana($userManaAfterUse);
                    $entityManager->persist($user);
                }
                $entityManager->flush();
            }else{

            }

        }else{
            $message = "Vous n'avez plus de cette potion. Action impossible";
        }



        return new JsonResponse([
            'life' =>  $user->getCurrentLife(),
            'mana' => $user->getCurrentMana(),
            'message' => $message
        ]);

    }


    #[Route("/joueur/buy/shop", name:"joueur_buy_shop", methods: ["POST"])]
    public function playerBuyItem(
        Request                         $request,
        EquipementRepository            $equipementRepository,
        InventaireRepository            $inventaireRepository,
        InventaireEquipementRepository  $inventaireEquipementRepository,
        EntityManagerInterface          $entityManager,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $idEquipement = $data['item'];
        $equipementEntity = $equipementRepository->find($idEquipement);
        $user = $this->getUser();
        $moneyAfterBuy = $user->getMoney() - $equipementEntity->getPrixAchat();

        if($moneyAfterBuy < 0){
            return new JsonResponse([
                'money' => $user->getMoney(),
                'message' => "Vous n'avez pas assez d'or pour acheter cet objet."
            ]);
        }

        $inventaireEntity = $inventaireRepository->findOneBy(['user' => $user->getId()]);
        $shouldIncrementExistingEquipement = $inventaireEquipementRepository->findOneBy(['inventaire' => $inventaireEntity->getId(), 'equipement' => $idEquipement]);

        if($shouldIncrementExistingEquipement){
            $shouldIncrementExistingEquipement->setQuantity($shouldIncrementExistingEquipement->getQuantity() + 1);
            $entityManager->persist($shouldIncrementExistingEquipement);
        }else{
            $inventaireEquipementEntity = new InventaireEquipement();
            $inventaireEquipementEntity->setQuantity(1);
            $inventaireEquipementEntity->setEquipement($equipementEntity);
            $inventaireEquipementEntity->setInventaire($inventaireEntity);
            $entityManager->persist($inventaireEquipementEntity);
        }

        $user->setMoney($moneyAfterBuy);
        $entityManager->persist($user);
        $entityManager->flush();

        return new JsonResponse(['money' => $moneyAfterBuy]);
    }


    #[Route("/chat/init", name:"chat_init", methods: ["POST"])]
    public function initChat(){
        //$chat = new ChatService();
    }


    #[Route("/joueur/guilde/join", name:"joueur_guilde_join", methods: ["POST"])]
    public function joueurGuildeJoin(
        Request                 $request,
        GuildeRepository        $guildeRepository,
        EntityManagerInterface  $entityManager,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $guildeEntity = $guildeRepository->find($data['guildeId']);
        $user = $this->getUser();

        /**todo verifier le nombre de personnes dans la guilde **/
        /**todo faire un système de notification pour le baron de la guilde*/

        $joueurGuildeEntity = new JoueurGuilde();
        $joueurGuildeEntity->setUser($user);
        $joueurGuildeEntity->setGuilde($guildeEntity);
        $joueurGuildeEntity->setGrade('recrue');

        $entityManager->persist($joueurGuildeEntity);
        $entityManager->flush();

        $message = "Vous candidature à été envoyer au baron de la guilde {$guildeEntity->getNom()}";

        return new JsonResponse([
            'message' =>  $message,
        ]);

    }


    #[Route("/joueur/add/friend", name:"joueur_add_friend", methods: ["POST"])]
    public function joueurAddFriend(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response {
        $data = json_decode($request->getContent(), true);
        $userAdded = $data['userId'];
        $userAddedEntity = $userRepository->find($userAdded);
        $userEntity = $this->getUser();

        $friendEntity = new Friend();
        $friendEntity->setUser1($userEntity);
        $friendEntity->setUser2($userAddedEntity);

        $entityManager->persist($friendEntity);
        $entityManager->flush();


        return new JsonResponse([
            'message' =>  "Le joueur {$userAddedEntity->getPseudo()} a bien été ajouté à votre liste d'amis",
            'friendId' => $friendEntity->getId()
        ]);
    }


    #[Route("/joueur/remove/friend", name:"joueur_remove_friend", methods: ["POST"])]
    public function joueurRemvoveFriend(Request $request, FriendRepository $friendRepository): Response {
        $data = json_decode($request->getContent(), true);
        $friendId = $data['friendId'];
        $friendEntity = $friendRepository->find($friendId);

        try {
            $friendRepository->remove($friendEntity);
        } catch (OptimisticLockException $e) {
        } catch (ORMException $e) {
        }

        return new JsonResponse([
            'message' =>  "Vous n'êtes désormais plus amis.",
        ]);
    }


}