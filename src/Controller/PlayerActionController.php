<?php

namespace App\Controller;

use App\Entity\Friend;
use App\Entity\InventaireEquipement;
use App\Entity\JoueurGuilde;
use App\Enum\Classe;
use App\Event\NextQuestSequenceEvent;
use App\Repository\BossRecompenseRepository;
use App\Repository\BossRepository;
use App\Repository\ClasseRepository;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\FriendRepository;
use App\Repository\GuildeRepository;
use App\Repository\InventaireConsommableRepository;
use App\Repository\InventaireEquipementRepository;
use App\Repository\InventaireRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\QueteRepository;
use App\Repository\RecompenseRepository;
use App\Repository\SequenceActionRepository;
use App\Repository\SequenceRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserQueteRepository;
use App\Repository\UserRepository;
use App\service\DeathService;
use App\service\HistoriqueService;
use App\service\InventaireService;
use App\service\LevelingService;
use App\service\QuestService;
use App\service\SpellService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;



#[Route("/api", name:"api_")]
class PlayerActionController extends AbstractController
{
    public function __construct(){}

    #[Route("/joueur/attack/joueur", name:"joueur_attack_joueur")]
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
            $spellService->applyBuffEffect($target, $spell);
            $valueReturned = 0;
            $message = "Vous utilisez {$spell->getNom()} sur {$target->getPseudo()}";
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


    #[Route("/joueur/attack/monster", name:"joueur_attack_monster")]
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


    #[Route("/joueur/attack/boss", name:"joueur_attack_boss")]
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


    #[Route("/joueur/spell/self", name:"joueur_spell_self")]
    public function useSpellAutoFocused(
        Request             $request,
        SortilegeRepository $sortilegeRepository,
        SpellService        $spellService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $spell = $sortilegeRepository->find($data['spellId']);
        $arrayStat = [];
        $user = $this->getUser();

        if($spell->getType() === "soin"){
            $arrayStat = $spellService->healPlayer($user, $spell, $user);
            $message =  "Vous vous soignez avec {$spell->getNom()}, vous récupèrez {$arrayStat['value']} points de vie <br />";

        }else if ($spell->getType() === "buff"){
            $buffApplyed = $spellService->applyBuffEffect($user, $spell);
            if($buffApplyed){
                $message =  "Vous êtes maintenant sous les effets de {$spell->getNom()} <br />";
            }
        }

        return new Response(json_encode([
            'message' => $message,
            'life' => $arrayStat['life'] ?? $user->getCurrentLife(),
            'needRefresh' => true
        ]));
    }


    #[Route("/user/choice/classe", name:"user_choose_class")]
    public function chooseAClass(
        Request                         $request,
        InventaireService               $inventaireService,
        ClasseRepository                $classeRepository,
        UserRepository                  $userRepository,
        SequenceActionRepository        $sequenceActionRepository,
        EquipementRepository            $equipementRepository,
        EventDispatcherInterface        $eventDispatcher
    ): Response {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $classeEntity = $classeRepository->findOneBy(['nom' => $data['classe']]);
        $userRepository->updateClasse($classeEntity->getId(), $this->getUser()->getId());
        $sequenceId = $sequenceActionRepository->getSequenceByAction($data['actionId']);

        $message = "La classe à bien été modifiée.<br />";

        /* todo : mettre en place des récompense "par action" dans les quêtes + utiliser les id */
        switch ($data['classe']){
            case Classe::ARCHER->value:
                $equipement = $equipementRepository->findOneBy(['nom' => "Arc de l'archer débutant"]);
                break;
            case Classe::SORCIER->value:
                $equipement = $equipementRepository->findOneBy(['nom' => "Baton du sorcier débutant"]);
                break;
            case Classe::GUERRIER->value:
                $equipement = $equipementRepository->findOneBy(['nom' => "Epée du guerrier débutant"]);
                break;
            case Classe::MOINE->value:
                $equipement = $equipementRepository->findOneBy(['nom' => "Baton du moine débutant"]);
                break;
        }

        $inventaireService->addEquipementToUserInventaire($user->getId(), $equipement->getId());

        $eventDispatcher->dispatch(new NextQuestSequenceEvent($user, $sequenceId));
        $json = json_encode(['message' => $message]);

        return new Response($json);
    }


    #[Route("/joueur/use/consommable", name:"joueur_use_consommable")]
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



        return new Response(json_encode([
            'life' =>  $user->getCurrentLife(),
            'mana' => $user->getCurrentMana(),
            'message' => $message
        ]));

    }


    #[Route("/joueur/buy/shop", name:"joueur_buy_shop")]
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

        $message = "";

        if($moneyAfterBuy >= 0){

            $inventaireEntity = $inventaireRepository->findOneBy(['user' => $user->getId()]);
            $shouldIncrementExistingEquipement = $inventaireEquipementRepository->findOneBy(['inventaire' => $inventaireEntity->getId(), 'equipement' => $idEquipement]);

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

            $user->setMoney($moneyAfterBuy);
            $entityManager->persist($user);
            $entityManager->flush();
        }

        return new Response(json_encode(['money' => $moneyAfterBuy]));
    }


    #[Route("/chat/init", name:"chat_init")]
    public function initChat(){
        //$chat = new ChatService();
    }


    #[Route("/joueur/guilde/join", name:"joueur_guilde_join")]
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

        return new Response(json_encode([
            'message' =>  $message,
        ]));

    }


    #[Route("/user/recompense/boss", name:"joueur_recompense_boss")]
    public function getRecompenseBoss(Request $request, BossRecompenseRepository $bossRecompenseRepository): Response {
        $data = json_decode($request->getContent(), true);
        $recompenses = $bossRecompenseRepository->findBy(['boss' => $data['bossId']]);

        $recompenseEntity = $recompenses[0]->getRecompense();
        $message = "";
        if(!is_null($recompenseEntity->getEquipement())){
            $message .= "Vous gagnez {$recompenseEntity->getEquipement()->getNom()}. <br />";
        }
        if(!is_null($recompenseEntity->getMoney())){
            $message .= "Vous gagnez {$recompenseEntity->getMoney()} pièces d'or. <br />";
        }
        if(!is_null($recompenseEntity->getExperience())){
            $message .= "Vous gagnez {$recompenseEntity->getExperience()} points d'expérience. <br />";
        }

        return new Response(json_encode([
            'message' =>  $message,
        ]));
    }


    #[Route("/joueur/add/friend", name:"joueur_add_friend")]
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


        return new Response(json_encode([
            'message' =>  "Le joueur {$userAddedEntity->getPseudo()} a bien été ajouté à votre liste d'amis",
            'friendId' => $friendEntity->getId()
        ]));
    }


    #[Route("/joueur/remove/friend", name:"joueur_remove_friend")]
    public function joueurRemvoveFriend(Request $request, FriendRepository $friendRepository): Response {
        $data = json_decode($request->getContent(), true);
        $friendId = $data['friendId'];
        $friendEntity = $friendRepository->find($friendId);

        try {
            $friendRepository->remove($friendEntity);
        } catch (OptimisticLockException $e) {
        } catch (ORMException $e) {
        }

        return new Response(json_encode([
            'message' =>  "Vous n'êtes désormais plus amis.",
        ]));
    }


    #[Route("/joueur/quete/next", name:"joueur_quete_next")]
    public function nextSequence(Request $request, QueteRepository $queteRepository, UserQueteRepository $userQueteRepository, EntityManagerInterface $entityManager): Response {
        $data = json_decode($request->getContent(), true);
        $questId = $data['questId'];
        $questEntity = $queteRepository->find($questId);
        $user = $this->getUser();
        $actualQuestPosition = $userQueteRepository->findOneBy(['user' => $user, 'quete' => $questEntity]);
        $actualSequence = $actualQuestPosition->getSequence();
        if(!$actualSequence->getIsLast()){
            $nextSequence = $actualSequence->getNextSequence();
            $actualQuestPosition->setSequence($nextSequence);
            $entityManager->persist($actualQuestPosition);
            $entityManager->flush();
        }else{
            $actualQuestPosition->setIsDone(true);
            $entityManager->persist($actualQuestPosition);
            $entityManager->flush();
        }


        return new Response(json_encode([
            'message' =>  "Vous avez bien avancé dans la quête.",
        ]));
    }

    public function changeSequence($sequenceName, $value){
      //  $sequence = $sequenceRepository->findOneBy(['name' => $sequenceName]);
      //  $sequence->setNextVal($value);
      //  $entityManager->persist($sequence);
     //   $entityManager->flush();
     //   return $sequence->getNextVal();
    }

    public function giveObjectToPnj(Request $request){

    }


}