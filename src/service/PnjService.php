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

    /**
     * Étal d'un PNJ marchand. Renvoie TOUJOURS une forme valide
     * {items, typeShop, title} dès qu'une boutique existe (jamais un tableau
     * nu qui ferait planter le front sur `shop.items`).
     *
     * Le front n'affiche pour l'instant que la section équipement : on la
     * renvoie pour tout type de boutique (y compris « mixte »), le prix par
     * ligne (shop_equipement.prix) primant sur le prix de base — via COALESCE
     * dans getEquipementsShop. L'affichage in-game des sections consommable /
     * objet reste à brancher (ShopView côté joueur).
     */
    public function getPnjShop(Pnj $pnj): array{

        $shop = $pnj->getShop();
        if($shop === null){
            return ['items' => [], 'typeShop' => 'equipement', 'title' => $pnj->getName()];
        }

        $items = $this->shopEquipementRepository->getEquipementsShop($shop->getId());
        foreach ($items as &$equipement){
            $equipement['caracteristiques'] = $this->equipementCaracteristiqueRepository
                ->getAllCaracteristiquesByIdEquipement($equipement['idEquipement']);
        }

        return [
            'items' => $items,
            'typeShop' => 'equipement',
            'title' => $shop->getName(),
        ];
    }

}