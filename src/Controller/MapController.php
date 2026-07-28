<?php

namespace App\Controller;

use App\Config\GameContent;
use App\Config\RecolteConfig;
use App\Entity\Carte;
use App\Entity\CarteCarreau;
use App\Entity\MonstreCarreau;
use App\Repository\CarreauRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\MonstreRepository;
use App\Repository\PnjRepository;
use App\Repository\InteractionRepository;
use App\Repository\WrapRepository;
use App\service\DonjonMapView;
use App\service\DonjonTeleportService;
use App\service\InteractionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route("/api", name:"api_")]
class MapController extends AbstractController
{

    public function __construct(){}

    #[Route("/map/cases/data", name:"get_map_cases_data", methods: ["POST"])]
    public function getMapAndCasesData(
        Request         $request,
        CarteRepository $carteRepository,
        DonjonMapView   $donjonMapView,
        DonjonTeleportService $donjonTeleportService,
        InteractionService $interactionService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $mapId = $data['mapId'];
        $user = $this->getUser();

        // En instance, l'occupation ne vient plus de carte_carreau (OneToOne global) :
        // DonjonMapView réinjecte les membres du groupe du joueur.
        $vue = $donjonMapView->casesPourJoueur($user, $mapId);

        // Salle de donjon sans instance active (durée max dépassée pendant une déconnexion) :
        // on repose le joueur dehors plutôt que de le laisser dans un décor fantôme.
        //
        // ⚠️ SEULEMENT si le joueur est RÉELLEMENT sur cette carte. Demander les cases
        // d'une salle de donjon où l'on ne se trouve pas est une simple CONSULTATION (le
        // MapMaker, qui charge n'importe quelle carte par cet endpoint) : sans ce garde-fou,
        // ouvrir une carte de donjon dans le MapMaker téléportait l'admin à la sortie du
        // donjon ET renvoyait les cases de la carte de SORTIE avec l'id demandé — d'où des
        // collisions incohérentes, celles d'une autre carte, par-dessus le fond de la salle.
        $surCetteCarte = (int)$user->getMap()?->getId() === (int)$mapId;
        if ($vue['ejection'] !== null && $surCetteCarte) {
            $sortie = $donjonTeleportService->reposerDehors($user, $vue['ejection']);
            $vue = $donjonMapView->casesPourJoueur($user, $sortie['carteId']);
            $mapId = $sortie['carteId'];
        }

        $returnMapInfo = [];
        $returnMapInfo['cases'] = $vue['cases'];
        $returnMapInfo['mapInfo'] = $carteRepository->getMapName($mapId);
        $returnMapInfo['mapId'] = $mapId;
        $returnMapInfo['instanceId'] = $vue['instanceId'];
        // Le front ouvre la modale de groupe sur ces cases au lieu de franchir la porte.
        $returnMapInfo['portesDonjon'] = $donjonMapView->portesDeDonjon($vue['cases'], (int)$mapId);
        // Position du joueur : un rechargement de carte doit pouvoir tout resynchroniser,
        // y compris après une téléportation décidée par le serveur (entrée en groupe, mort).
        $returnMapInfo['abscisseJoueur'] = $user->getCaseAbscisse();
        $returnMapInfo['ordonneeJoueur'] = $user->getCaseOrdonnee();
        // État des cases interactives (disponible ? pourquoi pas ? rechargée quand ?).
        // Purement informatif : `executer` revérifie tout.
        $returnMapInfo['interactions'] = $interactionService->decrireCases($user, $vue['cases']);
        // Les deux manières de récolter, avec leurs libellés et leurs chiffres. Descendues
        // avec la carte plutôt que fetchées à l'ouverture de la modale : ce sont deux
        // constantes, et le joueur ne doit pas attendre un aller-retour pour choisir.
        // Le front n'écrit ainsi aucun chiffre en dur — retoucher l'équilibrage mentirait
        // sinon à l'écran.
        $returnMapInfo['modesRecolte'] = RecolteConfig::modes();

        return new JsonResponse($returnMapInfo);
    }


    #[Route("/map/all", name:"get_all_maps", methods: ["POST"])]
    public function getAllMaps(CarteRepository $carteRepository): Response {
        $maps = $carteRepository->getAllMap();
        return new JsonResponse($maps);
    }


    #[Route("/map/create", name:"create_map", methods: ["POST"])]
    public function createmap(Request $request, CarreauRepository $carreauRepository, EntityManagerInterface $entityManager): Response
    {
        $data = json_decode($request->getContent(), true);
        $case = $carreauRepository->find(GameContent::DEFAULT_CARREAU_ID);
        $carte = new Carte();
        $carte->setPosition("");
        $carte->setAbscisse(0);
        $carte->setOrdonnee(0);
        $carte->setNom($data['name']);
        $carte->setIsInstance(false);
        $carte->setIsCimetiere(false);
        $carte->setIsAuberge(false);
        $entityManager->persist($carte);
        $entityManager->flush();

        for($i = 0; $i <= 15; $i++){
            for($j = 0; $j <= 23; $j++){

                $caseCarte = new CarteCarreau();
                $caseCarte->setCarreau($case);
                $caseCarte->setCarte($carte);
                $caseCarte->setAbscisse($j);
                $caseCarte->setOrdonnee($i);
                $caseCarte->setIsUsable(true);
                $caseCarte->setIsWrap(false);
                $entityManager->persist($caseCarte);
                $entityManager->flush();
            }
        }
        return new Response("ok");

    }


    // MapMaker : update les élements de la map
    #[Route("/map/update", name:"update_map", methods: ["POST"])]
    public function updateMap(
        Request                     $request,
        CarteCarreauRepository      $carteCarreauRepository,
        PnjRepository               $pnjRepository,
        MonstreRepository           $monstreRepository,
        MonstreCarreauRepository    $monstreCarreauRepository,
        WrapRepository              $wrapRepository,
        InteractionRepository       $interactionRepository,
        EntityManagerInterface      $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $cases = $carteCarreauRepository->getAllCasesOfMap($data['mapId']);

        // Les cases de LA carte éditée, et elles seules. Le payload vient du client et
        // porte des `carteCarreauId` : sans ce filtre, un front qui affiche par erreur les
        // cases d'une autre carte (c'est arrivé sur les salles de donjon) écrit dans cette
        // autre carte — une carte du monde ouvert repeinte en silence, sans rien pour le
        // signaler. On saute la ligne au lieu de l'écrire.
        $idsDeLaCarte = array_column($cases, 'carteCarreauId');
        $idsDeLaCarte = array_combine($idsDeLaCarte, $idsDeLaCarte);

        foreach ($cases as $key => $case){
            if($case !== $data['cases'][$key]){
                $updatedCase = $data['cases'][$key];
                if(!isset($idsDeLaCarte[$updatedCase['carteCarreauId']])){
                    continue;
                }
                $carteCarreauEntity = $carteCarreauRepository->findByIdCarteCarreau($updatedCase['carteCarreauId'])[0];
                $carteCarreauEntity->setIsUsable($updatedCase['isUsable']);
                $carteCarreauEntity->setIsWrap($updatedCase['isWrap']);

                if($updatedCase['pnjId']){
                    $pnjEntity = $pnjRepository->find($updatedCase['pnjId']);
                    $carteCarreauEntity->setPnj($pnjEntity);
                }

                // Interaction posée sur la case. On traite explicitement le RETRAIT
                // (interactionId à null) : sans ça, une case interactive ne pourrait plus
                // jamais être libérée depuis le MapMaker.
                if(array_key_exists('interactionId', $updatedCase)){
                    $carteCarreauEntity->setInteraction(
                        $updatedCase['interactionId']
                            ? $interactionRepository->find($updatedCase['interactionId'])
                            : null
                    );
                }

                if($updatedCase['isWrap']){
                    $carteCarreauEntity->setIsWrap(true);
                    $carteCarreauEntity->setTargetWrap($updatedCase['targetWrap']);
                    $carteCarreauEntity->setTargetMapId($updatedCase['targetMapId']);
                    $wrap = $wrapRepository->find(GameContent::DEFAULT_WRAP_ID);
                    $carteCarreauEntity->setWrap($wrap);
                }

                if($updatedCase['hasMonstre']){
                    $monstreCarreauEntity = $monstreCarreauRepository->findOneBy(['monstre' => $updatedCase['hasMonstre'], 'carteCarreau' => $carteCarreauEntity->getId()]);
                    if(!$monstreCarreauEntity){
                        $monstreEntity = $monstreRepository->findOneBy(['id' => $updatedCase['hasMonstre']]);
                        $monstreCarreauEntity = new MonstreCarreau();
                        $monstreCarreauEntity->setMonstre($monstreEntity);
                        $monstreCarreauEntity->setCarteCarreau($carteCarreauEntity);
                        $monstreCarreauEntity->setCurrentLife($monstreEntity->getMaxLife());
                        $monstreCarreauEntity->setQuantityBase($updatedCase['monstreQuantity']);
                        $monstreCarreauEntity->setQuantity($updatedCase['monstreQuantity']);
                        $entityManager->persist($monstreCarreauEntity);
                        $entityManager->flush();
                        $newMonstreCarreauEntity = $monstreCarreauRepository->findOneBy(['monstre' => $updatedCase['hasMonstre'], 'carteCarreau' => $carteCarreauEntity->getId()]);
                        $carteCarreauEntity->setMonstreCarreau($newMonstreCarreauEntity);
                    }

                }

                $entityManager->persist($carteCarreauEntity);
                $entityManager->flush();
            }
        }

        return new Response("ok");
    }

    //MapMaker : update les élements de la map
    #[Route("/map/cases/infos", name:"map_cases_infos", methods: ["POST"])]
    public function getCasesInfoForSelect(Request $request, CarteCarreauRepository $carteCarreauRepository): Response {
        $data = json_decode($request->getContent(), true);
        $mapId = $data['mapId'];

        $casesInfos = $carteCarreauRepository->getCasesInfoForSelect($mapId);

        return new JsonResponse($casesInfos);
    }

}
