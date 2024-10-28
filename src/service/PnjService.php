<?php

namespace App\service;

use App\Entity\Pnj;
use App\Repository\EquipementCaracteristiqueRepository;
use App\Repository\ShopEquipementRepository;
use App\Repository\ShopObjetRepository;
use App\Repository\ShopRepository;

class PnjService {

    public function __construct(
        private ShopRepository $shopRepository,
        private ShopEquipementRepository $shopEquipementRepository,
        private ShopObjetRepository $shopObjetRepository,
        private EquipementCaracteristiqueRepository $equipementCaracteristiqueRepository,
    ){
    }

    public function getPnjShop(Pnj $pnj): array{

        $shopInfos = [];
        if($pnj->getShop()){
            $shop = $this->shopRepository->find($pnj->getShop());
            //peut être enlever les break et faire quelque chose d'incrémental
            switch ($shop->getType()){
                case "equipement":
                    $items = $this->shopEquipementRepository->getEquipementsShop($shop->getId());
                    foreach ($items as &$equipement){
                        $caracterisitques = $this->equipementCaracteristiqueRepository->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
                        $equipement['caracteristiques'] = $caracterisitques;
                    }
                    $shopInfos = [
                        'items' => $items,
                        'typeShop' => $shop->getType(),
                        'title' =>  $shop->getName()
                    ];
                    break;
                case "objet":
                    $items = $this->shopObjetRepository->findBY(['shop' => $shop->getId()]);
                    $shopInfos = [
                        'items' => $items,
                        'typeShop' => $shop->getType(),
                        'title' =>  $shop->getName()
                    ];
                    break;
                case "consommable":
                    break;
            }
        }


        return $shopInfos;
    }

}