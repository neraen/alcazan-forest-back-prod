<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Dialogue;
use App\Entity\Quete;
use App\Entity\Recompense;
use App\Entity\Sequence;
use App\Entity\SequenceAction;
use App\Entity\UserQuete;
use App\Enum\ActionType;
use App\Repository\ActionRepository;
use App\Repository\AlignementRepository;
use App\Repository\BossRepository;
use App\Repository\ConsommableRepository;
use App\Repository\DialogueRepository;
use App\Repository\EquipementRepository;
use App\Repository\ObjetRepository;
use App\Repository\PnjRepository;
use App\Repository\QueteRepository;
use App\Repository\RecompenseRepository;
use App\Repository\SequenceActionRepository;
use App\Repository\SequenceRepository;
use App\Repository\UserQueteRepository;
use App\Repository\UserSequenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


class QuestControlleur extends AbstractController
{
    public function __construct(){}

    #[Route("/api/pnj/sequance", name:"api_pnj_sequance")]
    public function getPnjSequence(
        Request                     $request,
        PnjRepository               $pnjRepository,
        UserQueteRepository         $userQueteRepository,
        SequenceRepository          $sequenceRepository,
        SequenceActionRepository    $sequenceActionRepository
    ): Response {
        $dataPost = json_decode($request->getContent(), true);
        $pnj = $pnjRepository->find($dataPost['pnjId']);
        $quete = $pnj->getQuete();
        $user = $this->getUser();

        $joueurHasBeginQuest = $userQueteRepository->findOneBy(['user' => $user->getId(), 'quete' => $quete->getId()]);

        $sequence = null;
        if($joueurHasBeginQuest){
            /** Gestion du cas ou la last id done */

            $sequence = $joueurHasBeginQuest->getSequence();

        }else{
            $sequence = $sequenceRepository->findOneBy(['pnj' => $pnj->getId(), 'quete' => $quete->getId(), 'position' => 1]);
            $userQuete = new UserQuete();
            $userQuete->setIsDone(false);
            $userQuete->setUser($user);
            $userQuete->setQuete($quete);
            $userQuete->setSequence($sequence);
        }

        $dialogue = $sequence->getDialogue()->getContenu();
        if($sequence->getHasAction()){
            $actions = $sequenceActionRepository->getAllActionsBySequence($sequence->getId());
        }

        $questData = [
            'title' => $quete->getName(),
            'dialogue' => $dialogue ?? '',
            'actions' => $actions ?? []
        ];

        $sequenceResponse = json_encode($questData);
        return new Response($sequenceResponse);
    }

    #[Route("/api/quests", name:"api_quests")]
    public function getAllQuests(QueteRepository $queteRepository): Response
    {

        $quests = $queteRepository->findAll();
        $questsData = [];
        foreach($quests as $quest){
            $questsData[] = [
                'id' => $quest->getId(),
                'name' => $quest->getName()
            ];
        }
        $questsResponse = json_encode($questsData);
        return new Response($questsResponse);
    }


    #[Route("/api/quest", name:"api_quest")]
    public function getQuest(
        Request                     $request,
        QueteRepository             $queteRepository,
        SequenceRepository          $sequenceRepository,
        SequenceActionRepository    $sequenceActionRepository,
        RecompenseRepository        $recompenseRepository
    ): Response {

        $requestContent = json_decode($request->getContent(), true);
        $quest = $queteRepository->findOneBy(['id' => $requestContent['questId']]);
        $sequences = $sequenceRepository->findBy(['quete' => $quest->getId()]);

        $sequencesData = [
            'id' => $quest->getId(),
            'name' => $quest->getName(),
            'alignement' => $quest->getAlignement() ? $quest->getAlignement()->getId() : 0,
            'objet' => $quest->getObjet() ? $quest->getObjet()->getId() : 0,
            'level' => $quest->getMinimalLevel() ?? 0,
        ];


        foreach($sequences as $sequence){
            $recompense = $recompenseRepository->findOneBy(['sequence' => $sequence->getId()]);
            $recompense = $recompense ? [
                'money' => $recompense->getMoney() ?? 0,
                'experience' => $recompense->getExperience() ?? 0,
                'objet' => $recompense->getObjet() ? $recompense->getObjet()->getId() : 0,
                'equipement' => $recompense->getEquipement() ? $recompense->getEquipement()->getId() : 0,
                'consommable' => $recompense->getConsommable() ? $recompense->getConsommable()->getId() : 0,
                'quantity' => $recompense->getQuantity() ?? 0
            ] : [];
            $sequencesData['sequences'][] = [
                'id' => $sequence->getId(),
                'position' => $sequence->getPosition(),
                'actions' => $sequenceActionRepository->getAllActionsBySequence($sequence->getId()),
                'isLast' => $sequence->getIsLast(),
                'dialogueContent' => $sequence->getDialogue()->getContenu(),
                'dialogueTitre' => $sequence->getDialogue()->getTitre(),
                'dialogueId' => $sequence->getDialogue()->getId(),
                'pnj' => $sequence->getPnj()->getId(),
                'lastSequence' => $sequence->getLastSequence() ? $sequence->getLastSequence()->getId() : 0,
                'nextSequence' => $sequence->getNextSequence() ? $sequence->getNextSequence()->getId() : 0,
                'nomSequence' => $sequence->getName(),
                'recompense' => $recompense
            ];
        }

        $sequencesResponse = json_encode($sequencesData);
        return new Response($sequencesResponse);
    }

    #[Route("/api/quest/infos", name:"api_quest_infos")]
    public function getQuestSelectInfos(
        AlignementRepository    $alignementRepository,
        ObjetRepository         $objetRepository
    ): Response{
        $alignements = $alignementRepository->findAll();
        $objets = $objetRepository->findAll();
        $selectsData = [];
        foreach($alignements as $alignement){
            $selectsData['alignements'][] = [
                'id' => $alignement->getId(),
                'name' => $alignement->getNom()
            ];
        }

        foreach($objets as $objet){
            $selectsData['objets'][] = [
                'id' => $objet->getId(),
                'name' => $objet->getName()
            ];
        }

        $selectsDataResponse = json_encode($selectsData);
        return new Response($selectsDataResponse);
    }


    #[Route("/api/quest/create", name:"api_quest_create")]
    public function createQuestEntete(Request $request, EntityManagerInterface $entityManager): Response
    {
        $data = json_decode($request->getContent(), true);
        $quest = new Quete();
        $quest->setName($data['name']);
        $entityManager->persist($quest);
        $entityManager->flush();

        return new Response('ok');
    }

    #[Route("/api/quest/update", name:"api_quest_update")]
    public function updateQuest(
        Request                     $request,
        QueteRepository             $queteRepository,
        AlignementRepository        $alignementRepository,
        ObjetRepository             $objetRepository,
        BossRepository              $bossRepository,
        ConsommableRepository       $consommableRepository,
        SequenceRepository          $sequenceRepository,
        SequenceActionRepository    $sequenceActionRepository,
        RecompenseRepository        $recompenseRepository,
        EquipementRepository        $equipementRepository,
        PnjRepository               $pnjRepository,
        ActionRepository            $actionRepository,
        DialogueRepository          $dialogueRepository,
        EntityManagerInterface      $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $questId = $data['questId'];
        $questEntity = $queteRepository->find($questId);


        /* Prerequis de la quete */
        $alignement = $data['quest']['alignement'] > 0 ? $alignementRepository->find($data['quest']['alignement']) : null;
        $objet = $data['quest']['objet'] > 0 ? $objetRepository->find($data['quest']['objet']) : null;

        $questEntity->setAlignement($alignement);
        $questEntity->setObjet($objet);
        $questEntity->setMinimalLevel($data['quest']['level']);
        $questEntity->setName($data['quest']['name']);

        /* Sequences de la quete */
        $sequences = $data['quest']['sequences'];
        foreach ($sequences as $sequence){
            if($sequence['id'] > 0){
                $sequenceEntity = $sequenceRepository->find($sequence['id']);
            } else {
                $sequenceEntity = new Sequence();
            }

            $sequenceEntity->setPosition($sequence['position']);
            $sequenceEntity->setIsLast($sequence['isLast']);
            $sequenceEntity->setQuete($questEntity);
            $sequenceEntity->setName($sequence['nomSequence']);

            /** Recompense  */
            if($sequence['recompense'] !== []){
                $recompenseEntity = $recompenseRepository->findOneBy(['sequence' => $sequenceEntity->getId()]);
                if(!$recompenseEntity){
                    $recompenseEntity = new Recompense();
                    if(isset($sequence['recompense']['argent']) && $sequence['recompense']['argent'] > 0){
                        $recompenseEntity->setMoney($sequence['recompense']['argent']);
                    }
                    if(isset($sequence['recompense']['experience']) && $sequence['recompense']['experience'] > 0){
                        $recompenseEntity->setExperience($sequence['recompense']['experience']);
                    }
                    if(isset($sequence['recompense']['objet']) && $sequence['recompense']['objet'] > 0){
                        $recompenseEntity->setObjet($objetRepository->find($sequence['recompense']['objet']));
                    }
                    if(isset($sequence['recompense']['equipement']) && $sequence['recompense']['equipement'] > 0){
                        $recompenseEntity->setEquipement($equipementRepository->find($sequence['recompense']['equipement']));
                    }

                    $recompenseEntity->setSequence($sequenceEntity);
                    $entityManager->persist($recompenseEntity);
                    $entityManager->flush();
                }else{
                    if(isset($sequence['recompense']['argent']) && $sequence['recompense']['argent'] > 0){
                        $recompenseEntity->setMoney($sequence['recompense']['argent']);
                    }
                    if(isset($sequence['recompense']['experience']) && $sequence['recompense']['experience'] > 0){
                        $recompenseEntity->setExperience($sequence['recompense']['experience']);
                    }
                    if(isset($sequence['recompense']['objet']) && $sequence['recompense']['objet'] > 0){
                        $recompenseEntity->setObjet($objetRepository->find($sequence['recompense']['objet']));
                    }
                    if(isset($sequence['recompense']['equipement']) && $sequence['recompense']['equipement'] > 0){
                        $recompenseEntity->setEquipement($equipementRepository->find($sequence['recompense']['equipement']));
                    }

                    $recompenseEntity->setSequence($sequenceEntity);
                    $entityManager->persist($recompenseEntity);
                    $entityManager->flush();
                }
            }

            /** pnj */
            $pnj = $pnjRepository->find($sequence['pnj']);
            $sequenceEntity->setPnj($pnj);

            /** Dialogue */
            $dialogueEntity = $sequenceEntity->getDialogue();
            if(!$dialogueEntity){
                $dialogueEntity = new Dialogue();
            }

            $dialogueEntity->setContenu($sequence['dialogueContent']);
            $dialogueEntity->setTitre($sequence['dialogueTitre']);
            $entityManager->persist($dialogueEntity);
            $sequenceEntity->setDialogue($dialogueEntity);

            $entityManager->persist($sequenceEntity);
            $entityManager->flush();


            $sequenceActions = $sequence['actions'];
            if($sequenceActions !== []){
                foreach($sequenceActions as $index => $sequenceAction){
                    $action = $actionRepository->find($sequenceAction['actionId']);
                    $actionType = $actionRepository->find($sequenceAction['actionTypeId']);
                    if(!$action){
                        $action = new Action();
                        $actionType->setActionType($actionType);
                        $entityManager->persist($action);
                        $entityManager->flush();
                    }

                    switch ($actionType->getId()){
                        case ActionType::JSON->value:
                            $action->setParams($sequenceAction['actionParams']);
                            break;
                        case ActionType::POSSEDER_OBJET->value:
                        case ActionType::DONNER_OBJET->value:
                            $action->setObjet($objetRepository->find((int)$sequenceAction['objets']));
                            $action->setQuantity($sequenceAction['actionQuantity']);
                            break;
                        case ActionType::DONNER_EQUIPEMENT->value:
                            $action->setEquipement($equipementRepository->find((int)$sequenceAction['equipements']));
                            $action->setQuantity($sequenceAction['actionQuantity']);
                            break;
                        case ActionType::ATTEINDRE_LEVEL->value:
                        case ActionType::DONNER_OR->value:
                            $action->setQuantity($sequenceAction['actionQuantity']);
                            break;
                        case ActionType::BATTRE_BOSS->value:
                            $action->setBoss($bossRepository->find((int)$sequenceAction['bosses']));
                            break;
                        case ActionType::PARLER_PNJ->value:
                            $action->setPnj($pnjRepository->find((int)$sequenceAction['pnj']));
                            break;
                        case ActionType::DONNER_CONSOMMABLE->value:
                            $action->setConsommable($consommableRepository->find((int)$sequenceAction['consommables']));
                            $action->setQuantity($sequenceAction['actionQuantity']);
                            break;

                    }

                    $action->setName($sequenceAction['actionName']);
                    $entityManager->persist($action);
                    $entityManager->flush();

                    $sequenceActionEntity = $sequenceActionRepository->findOneBy(['sequence' => $sequenceEntity->getId(), 'action' => $action->getId()]);
                    if(!$sequenceActionEntity){
                        $sequenceActionEntity = new SequenceAction();
                        $sequenceActionEntity->setSequence($sequenceEntity);
                        $sequenceActionEntity->setAction($action);
                    }

                    $sequenceActionEntity->setPosition($index);
                    $entityManager->persist($sequenceActionEntity);
                    $entityManager->flush();
                }
            }
        }

        return new Response('ok');
    }
}