<?php

namespace App\service;

use App\Entity\Shop;
use App\Entity\ShopConsommable;
use App\Entity\ShopEquipement;
use App\Entity\ShopObjet;
use App\Exception\QuestException;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\ObjetRepository;
use App\Repository\PnjRepository;
use App\Repository\ShopRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Éditeur de boutiques (ShopMaker). Une boutique = un nom, des PNJ marchands
 * associés, et trois sections (équipements / consommables / objets) dont
 * chaque ligne porte un prix propre (null = prix de base de l'item).
 *
 * Les lignes de section n'ont aucune référence externe : à la sauvegarde on
 * les reconstruit intégralement depuis le payload (simple et sans churn d'id).
 *
 * Réutilise QuestException pour les erreurs métier (400 + message FR).
 */
class ShopEditorService
{
    public function __construct(
        private readonly ShopRepository $shopRepository,
        private readonly PnjRepository $pnjRepository,
        private readonly EquipementRepository $equipementRepository,
        private readonly ConsommableRepository $consommableRepository,
        private readonly ObjetRepository $objetRepository,
        private readonly EntityManagerInterface $entityManager
    ){}

    public function listShops(): array
    {
        $shops = [];
        foreach ($this->shopRepository->findAll() as $shop) {
            $pnjNames = array_map(fn ($pnj) => $pnj->getName(), $shop->getPnjs()->toArray());
            $shops[] = [
                'id' => $shop->getId(),
                'name' => $shop->getName(),
                'pnjName' => $pnjNames[0] ?? null,
                'nbItems' => $shop->getShopEquipements()->count()
                    + $shop->getShopConsommables()->count()
                    + $shop->getShopObjets()->count(),
            ];
        }

        return $shops;
    }

    /** Payload éditeur d'une boutique (miroir de ce que saveShop accepte). */
    public function getShopForEditor(int $shopId): array
    {
        $shop = $this->shopRepository->find($shopId);
        if ($shop === null) {
            throw new QuestException('Boutique introuvable.');
        }

        $equipements = [];
        foreach ($shop->getShopEquipements() as $line) {
            $equipement = $line->getEquipement();
            $equipements[] = [
                'itemId' => $equipement->getId(),
                'name' => $equipement->getNom(),
                'icone' => $equipement->getIcone(),
                'position' => $equipement->getPositionEquipement()?->getName(),
                'basePrix' => $equipement->getPrixAchat(),
                'prix' => $line->getPrix(),
            ];
        }

        $consommables = [];
        foreach ($shop->getShopConsommables() as $line) {
            $consommable = $line->getConsommable();
            $consommables[] = [
                'itemId' => $consommable->getId(),
                'name' => $consommable->getNom(),
                'icone' => $consommable->getIcone(),
                'basePrix' => $consommable->getPrixAchat(),
                'prix' => $line->getPrix(),
            ];
        }

        $objets = [];
        foreach ($shop->getShopObjets() as $line) {
            $objet = $line->getObjet();
            $objets[] = [
                'itemId' => $objet->getId(),
                'name' => $objet->getName(),
                'image' => $objet->getImage(),
                'basePrix' => $objet->getPrixVente(),
                'prix' => $line->getPrix(),
            ];
        }

        return [
            'id' => $shop->getId(),
            'name' => $shop->getName(),
            'pnjIds' => array_map(fn ($pnj) => $pnj->getId(), $shop->getPnjs()->toArray()),
            'equipements' => $equipements,
            'consommables' => $consommables,
            'objets' => $objets,
        ];
    }

    /** Catalogues pour l'éditeur : items achetables + PNJ marchands. */
    public function getReferentiels(): array
    {
        $equipements = array_map(fn ($equipement) => [
            'id' => $equipement->getId(),
            'name' => $equipement->getNom(),
            'icone' => $equipement->getIcone(),
            'position' => $equipement->getPositionEquipement()?->getName(),
            'basePrix' => $equipement->getPrixAchat(),
        ], $this->equipementRepository->findAll());

        $consommables = array_map(fn ($consommable) => [
            'id' => $consommable->getId(),
            'name' => $consommable->getNom(),
            'icone' => $consommable->getIcone(),
            'basePrix' => $consommable->getPrixAchat(),
        ], $this->consommableRepository->findAll());

        $objets = array_map(fn ($objet) => [
            'id' => $objet->getId(),
            'name' => $objet->getName(),
            'image' => $objet->getImage(),
            'basePrix' => $objet->getPrixVente(),
        ], $this->objetRepository->findAll());

        // PNJ candidats à tenir une boutique (type shop en priorité, + ceux déjà liés).
        $pnjs = [];
        foreach ($this->pnjRepository->findAll() as $pnj) {
            if ($pnj->getType() === 'shop' || $pnj->getShop() !== null) {
                $pnjs[] = [
                    'id' => $pnj->getId(),
                    'name' => $pnj->getName(),
                    'shopId' => $pnj->getShop()?->getId(),
                ];
            }
        }

        return compact('equipements', 'consommables', 'objets', 'pnjs');
    }

    /** Crée (id absent/0) ou met à jour une boutique complète. */
    public function saveShop(array $data): array
    {
        $shopId = $this->entityManager->wrapInTransaction(function () use ($data): int {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new QuestException('Le nom de la boutique est obligatoire.');
            }

            $shopId = (int)($data['id'] ?? 0);
            $shop = $shopId > 0 ? $this->shopRepository->find($shopId) : new Shop();
            if ($shop === null) {
                throw new QuestException('Boutique introuvable.');
            }

            $shop->setName($name);
            // Multi-sections : le type mono d'origine n'est plus contraignant.
            $shop->setType('mixte');
            $this->entityManager->persist($shop);
            $this->entityManager->flush();

            $this->syncPnjs($shop, array_map('intval', $data['pnjIds'] ?? []));
            $this->rebuildLines($shop, $data);

            $this->entityManager->flush();

            return $shop->getId();
        });

        // Les lignes ont été supprimées/recréées : on vide l'unité de travail
        // pour relire des collections fraîches (le côté inverse n'est pas synchronisé).
        $this->entityManager->clear();

        return $this->getShopForEditor($shopId);
    }

    public function deleteShop(int $shopId): void
    {
        $shop = $this->shopRepository->find($shopId);
        if ($shop === null) {
            throw new QuestException('Boutique introuvable.');
        }

        $this->entityManager->wrapInTransaction(function () use ($shop): void {
            foreach ($shop->getPnjs()->toArray() as $pnj) {
                $pnj->setShop(null);
                $this->entityManager->persist($pnj);
            }
            $this->removeLines($shop);
            $this->entityManager->remove($shop);
        });
    }

    /** Assigne exactement les PNJ demandés à cette boutique (les autres sont détachés). */
    private function syncPnjs(Shop $shop, array $pnjIds): void
    {
        foreach ($shop->getPnjs()->toArray() as $pnj) {
            if (!in_array($pnj->getId(), $pnjIds, true)) {
                $pnj->setShop(null);
                $this->entityManager->persist($pnj);
            }
        }

        foreach ($pnjIds as $pnjId) {
            $pnj = $this->pnjRepository->find($pnjId);
            if ($pnj !== null) {
                $pnj->setShop($shop);
                $this->entityManager->persist($pnj);
            }
        }
    }

    /** Reconstruit intégralement les 3 sections depuis le payload. */
    private function rebuildLines(Shop $shop, array $data): void
    {
        $this->removeLines($shop);
        $this->entityManager->flush();

        foreach ($data['equipements'] ?? [] as $row) {
            $equipement = $this->equipementRepository->find((int)($row['itemId'] ?? 0));
            if ($equipement === null) {
                continue;
            }
            $line = new ShopEquipement();
            $line->setShop($shop);
            $line->setEquipement($equipement);
            $line->setPrix($this->parsePrix($row['prix'] ?? null));
            $this->entityManager->persist($line);
        }

        foreach ($data['consommables'] ?? [] as $row) {
            $consommable = $this->consommableRepository->find((int)($row['itemId'] ?? 0));
            if ($consommable === null) {
                continue;
            }
            $line = new ShopConsommable();
            $line->setShop($shop);
            $line->setConsommable($consommable);
            $line->setPrix($this->parsePrix($row['prix'] ?? null));
            $this->entityManager->persist($line);
        }

        foreach ($data['objets'] ?? [] as $row) {
            $objet = $this->objetRepository->find((int)($row['itemId'] ?? 0));
            if ($objet === null) {
                continue;
            }
            $line = new ShopObjet();
            $line->setShop($shop);
            $line->setObjet($objet);
            $line->setPrix($this->parsePrix($row['prix'] ?? null));
            $this->entityManager->persist($line);
        }
    }

    private function removeLines(Shop $shop): void
    {
        foreach ($shop->getShopEquipements()->toArray() as $line) {
            $this->entityManager->remove($line);
        }
        foreach ($shop->getShopConsommables()->toArray() as $line) {
            $this->entityManager->remove($line);
        }
        foreach ($shop->getShopObjets()->toArray() as $line) {
            $this->entityManager->remove($line);
        }
    }

    private function parsePrix(mixed $value): ?int
    {
        $prix = (int)$value;

        return $prix > 0 ? $prix : null;
    }
}
