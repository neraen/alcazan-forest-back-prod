<?php

namespace App\Controller;

use App\Entity\Carte;
use App\Entity\CarteCarreau;
use App\Entity\MonstreCarreau;
use App\Repository\CarreauRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\MonstreRepository;
use App\Repository\PnjRepository;
use App\Repository\WrapRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/api", name:"api_")]
class MapController extends AbstractController
{

    public function __construct(){}

    #[Route("/map/cases/data", name:"get_map_cases_data")]
    public function getMapAndCasesData(Request $request, CarteCarreauRepository $carteCarreauRepository, CarteRepository $carteRepository): Response {
        $data = json_decode($request->getContent(), true);
        $mapId = $data['mapId'];
        $returnMapInfo = [];
        $returnMapInfo['cases'] = $carteCarreauRepository->getAllCasesOfMap($mapId);
        $returnMapInfo['mapInfo'] = $carteRepository->getMapName($mapId);
        $returnMapInfo['mapId'] = $mapId;

        return new Response(json_encode($returnMapInfo));
    }


    #[Route("/map/all", name:"get_all_maps")]
    public function getAllMaps(CarteRepository $carteRepository): Response {
        $maps = $carteRepository->getAllMap();
        return new Response(json_encode($maps));
    }


    #[Route("/map/create", name:"create_map")]
    public function createmap(Request $request, CarreauRepository $carreauRepository, EntityManagerInterface $entityManager): Response
    {
        $data = json_decode($request->getContent(), true);
        $case = $carreauRepository->find(1);
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
    #[Route("/map/update", name:"update_map")]
    public function updateMap(
        Request                     $request,
        CarteCarreauRepository      $carteCarreauRepository,
        PnjRepository               $pnjRepository,
        MonstreRepository           $monstreRepository,
        MonstreCarreauRepository    $monstreCarreauRepository,
        WrapRepository              $wrapRepository,
        EntityManagerInterface      $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true);
        $cases = $carteCarreauRepository->getAllCasesOfMap($data['mapId']);

        foreach ($cases as $key => $case){
            if($case !== $data['cases'][$key]){
                $updatedCase = $data['cases'][$key];
                $carteCarreauEntity = $carteCarreauRepository->findByIdCarteCarreau($updatedCase['carteCarreauId'])[0];
                $carteCarreauEntity->setIsUsable($updatedCase['isUsable']);
                $carteCarreauEntity->setIsWrap($updatedCase['isWrap']);

                if($updatedCase['pnjId']){
                    $pnjEntity = $pnjRepository->find($updatedCase['pnjId']);
                    $carteCarreauEntity->setPnj($pnjEntity);
                }

                if($updatedCase['isWrap']){
                    $carteCarreauEntity->setIsWrap(true);
                    $carteCarreauEntity->setTargetWrap($updatedCase['targetWrap']);
                    $carteCarreauEntity->setTargetMapId($updatedCase['targetMapId']);
                    $wrap = $wrapRepository->find(1);
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
    #[Route("/map/cases/infos", name:"map_cases_infos")]
    public function getCasesInfoForSelect(Request $request, CarteCarreauRepository $carteCarreauRepository): Response {
        $data = json_decode($request->getContent(), true);
        $mapId = $data['mapId'];

        $casesInfos = $carteCarreauRepository->getCasesInfoForSelect($mapId);

        return new Response(json_encode($casesInfos));
    }

}
