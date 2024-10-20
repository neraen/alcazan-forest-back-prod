<?php


namespace App\service;


use App\Entity\Carte;

class MapService
{
    public function __construct(){}

    public function getPositionAfterMapChange(array $cases, $targetCaseId): int{
        $adjacentCase = $this->getAdjacentCase($cases, $targetCaseId);

        foreach($adjacentCase as $case){
            if(is_null($case['userId']) && $case['isUsable'] && !$case['isWrap'] && is_null($case['pnjName'])){
                return $case['carteCarreauId'];
            }
        }

        $largeAdjacentCase = $this->getLargeAdjacentCase($cases, $targetCaseId);
        foreach($largeAdjacentCase as $case){
            if(is_null($case['userId']) && $case['isUsable'] && !$case['isWrap'] && is_null($case['pnjName'])){
                return $case['carteCarreauId'];
            }
        }

        throw new \Exception("No case available");
    }

    public function getAdjacentCase($cases, $targetCaseId){
       $wrapCaseFilter = array_filter($cases,  function($case) use ($targetCaseId){
           return $case['carteCarreauId'] === $targetCaseId;
       });

       $wrapCase = $wrapCaseFilter[array_keys($wrapCaseFilter)[0]];

       return array_filter($cases, function($case) use ($wrapCase){
           if(isset($case['abscisse']) && isset($wrapCase['abscisse']) && isset($case['ordonnee']) && isset($wrapCase['ordonnee'])){
               return ($case['abscisse'] == $wrapCase['abscisse']-1 && $case['ordonnee'] == $wrapCase['ordonnee']) ||
                   ($case['abscisse'] == $wrapCase['abscisse']-1 && $case['ordonnee'] == $wrapCase['ordonnee']-1) ||
                   ($case['abscisse'] == $wrapCase['abscisse'] && $case['ordonnee'] == $wrapCase['ordonnee']-1) ||
                   ($case['abscisse'] == $wrapCase['abscisse']+1 && $case['ordonnee'] == $wrapCase['ordonnee']-1) ||
                   ($case['abscisse'] == $wrapCase['abscisse']+1 && $case['ordonnee'] == $wrapCase['ordonnee']) ||
                   ($case['abscisse'] == $wrapCase['abscisse']+1 && $case['ordonnee'] == $wrapCase['ordonnee']+1) ||
                   ($case['abscisse'] == $wrapCase['abscisse'] && $case['ordonnee'] == $wrapCase['ordonnee']+1) ||
                   ($case['abscisse'] == $wrapCase['abscisse']-1 && $case['ordonnee'] == $wrapCase['ordonnee']+1);
           }else{
               return false;
           }
       });
    }

    public function getLargeAdjacentCase($cases, $targetCaseId){
        $wrapCaseFilter = array_filter($cases,  function($case) use ($targetCaseId){
            return $case['carteCarreauId'] === $targetCaseId;
        });

        $wrapCase = $wrapCaseFilter[array_keys($wrapCaseFilter)[0]];

        return array_filter($cases, function($case) use ($wrapCase){
            if(isset($case['abscisse']) && isset($wrapCase['abscisse']) && isset($case['ordonnee']) && isset($wrapCase['ordonnee'])){
                return ($case['abscisse'] == $wrapCase['abscisse']-2 && $case['ordonnee'] == $wrapCase['ordonnee']-2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']-2 && $case['ordonnee'] == $wrapCase['ordonnee']-1) ||
                    ($case['abscisse'] == $wrapCase['abscisse']-2 && $case['ordonnee'] == $wrapCase['ordonnee']) ||
                    ($case['abscisse'] == $wrapCase['abscisse']-2 && $case['ordonnee'] == $wrapCase['ordonnee']+1) ||
                    ($case['abscisse'] == $wrapCase['abscisse']-2 && $case['ordonnee'] == $wrapCase['ordonnee']+2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']-1 && $case['ordonnee'] == $wrapCase['ordonnee']+2) ||
                    ($case['abscisse'] == $wrapCase['abscisse'] && $case['ordonnee'] == $wrapCase['ordonnee']+2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+1 && $case['ordonnee'] == $wrapCase['ordonnee']+2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+2 && $case['ordonnee'] == $wrapCase['ordonnee']+2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+2 && $case['ordonnee'] == $wrapCase['ordonnee']+1) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+2 && $case['ordonnee'] == $wrapCase['ordonnee']) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+2 && $case['ordonnee'] == $wrapCase['ordonnee']-1) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+2 && $case['ordonnee'] == $wrapCase['ordonnee']-2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']+1 && $case['ordonnee'] == $wrapCase['ordonnee']-2) ||
                    ($case['abscisse'] == $wrapCase['abscisse'] && $case['ordonnee'] == $wrapCase['ordonnee']-2) ||
                    ($case['abscisse'] == $wrapCase['abscisse']-1 && $case['ordonnee'] == $wrapCase['ordonnee']-2);
            }else{
                return false;
            }
        });
    }

    public function getDistanceBetweenTwoMap(Carte $initialMap, Carte $comparativeMap): int{
        $distanceOrdonnee = abs($initialMap->getOrdonnee() - $comparativeMap->getOrdonnee());
        $distanceAbscisse = abs($initialMap->getAbscisse()-$comparativeMap->getAbscisse());
        $distanceTotale = sqrt(pow($distanceAbscisse, 2) + pow($distanceOrdonnee, 2));
        $distanceTotaleArrondie = round($distanceTotale);
        return $distanceTotaleArrondie;
    }

    public function getNearestMapInList(Carte $initialMap, array $maps): Carte{
        $minimalDistance = -1;
        $nearestMap = null;

        foreach ($maps as $map){
            $distance = $this->getDistanceBetweenTwoMap($initialMap, $map);
            if($minimalDistance === -1){
                $minimalDistance = $distance;
                $nearestMap = $map;
            }else{
                if($distance < $minimalDistance){
                    $minimalDistance = $distance;
                    $nearestMap = $map;
                }
            }
        }

        return $nearestMap;
    }

}