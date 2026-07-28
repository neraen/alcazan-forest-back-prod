<?php

namespace App\service;

use App\Entity\Equipement;
use App\Entity\JoueurCaracteristiqueBonus;
use App\Entity\User;
use App\Entity\UserEquipement;
use App\Enum\TypeItem;
use App\Repository\EquipementRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\UserEquipementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Machine à états unique du port d'équipement : équiper / retirer, avec le va-et-vient
 * sac ↔ emplacement et les bonus de caractéristiques associés.
 *
 * Invariants tenus par ce service :
 *  - un objet est TOUJOURS soit dans le sac, soit équipé — jamais les deux, jamais nulle part ;
 *  - un seul équipement porté par position ; l'échange remet l'ANCIEN objet dans le sac ;
 *  - les bonus de caractéristiques suivent exactement les objets portés (+ à l'équipement,
 *    − au retrait) ;
 *  - tout passe dans UNE transaction : à la moindre erreur, rien n'est écrit (c'est ce qui
 *    manquait à l'ancienne implémentation, où une suite de flush() pouvait faire disparaître
 *    un objet en cours de route).
 *
 * Les mouvements de sac passent par SacService : un exemplaire réservé (échange en cours)
 * n'est pas équipable.
 */
class EquipementEquipeService
{
    public function __construct(
        private readonly EntityManagerInterface               $entityManager,
        private readonly EquipementRepository                 $equipementRepository,
        private readonly UserEquipementRepository             $userEquipementRepository,
        private readonly JoueurCaracteristiqueBonusRepository $joueurCaracteristiqueBonusRepository,
        private readonly SacService                           $sacService
    ) {}

    /**
     * Équipe un objet du sac. Si un objet occupe déjà la même position, il retourne au sac.
     *
     * @throws \DomainException si l'objet n'existe pas, n'est pas possédé, est réservé,
     *                          ou est déjà porté
     */
    public function wear(User $user, int $idEquipement): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $idEquipement): void {
            $equipement = $this->equipementRepository->find($idEquipement);
            if ($equipement === null) {
                throw new \DomainException("Cet équipement n'existe pas.");
            }

            // Le joueur doit réellement posséder l'objet : sans ce garde-fou, poster un id
            // quelconque équipait un objet jamais acheté (et plantait sur le sac vide).
            if ($this->sacService->quantitePossedee($user, TypeItem::EQUIPEMENT, $idEquipement) < 1) {
                throw new \DomainException("Cet équipement n'est pas dans votre inventaire.");
            }

            $porte = $this->userEquipementRepository->getEquipementEquipeByUserAndPosition(
                $user->getId(),
                $equipement->getPositionEquipement()->getId()
            );

            if ($porte !== null) {
                if ($porte->getEquipement()->getId() === $equipement->getId()) {
                    throw new \DomainException("Cet équipement est déjà porté.");
                }

                // On lit l'ANCIEN équipement AVANT de détacher la ligne portée : c'est lui qui
                // doit revenir au sac. L'ancien code réinjectait le NOUVEL objet, ce qui le
                // dupliquait et faisait disparaître celui qu'on venait de retirer.
                $equipementRetire = $porte->getEquipement();
                $this->entityManager->remove($porte);
                $this->sacService->ajouterItem($user, TypeItem::EQUIPEMENT, $equipementRetire->getId(), 1);
                $this->appliquerCaracteristiques($user, $equipementRetire, -1);
            }

            // Contrôle aussi le disponible : un exemplaire réservé par un échange reste au sac.
            $this->sacService->retirerItem($user, TypeItem::EQUIPEMENT, $idEquipement, 1);

            $userEquipement = new UserEquipement();
            $userEquipement->setUser($user);
            $userEquipement->setEquipement($equipement);
            $this->entityManager->persist($userEquipement);

            $this->appliquerCaracteristiques($user, $equipement, 1);
        });
    }

    /**
     * Retire un objet porté et le remet dans le sac.
     *
     * @throws \DomainException si l'objet n'est pas équipé par ce joueur
     */
    public function unwear(User $user, int $idEquipement): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $idEquipement): void {
            $porte = $this->userEquipementRepository->findOneBy([
                'user' => $user,
                'equipement' => $idEquipement,
            ]);
            if ($porte === null) {
                throw new \DomainException("Cet équipement n'est pas équipé.");
            }

            $equipement = $porte->getEquipement();

            $this->entityManager->remove($porte);
            $this->sacService->ajouterItem($user, TypeItem::EQUIPEMENT, $equipement->getId(), 1);
            $this->appliquerCaracteristiques($user, $equipement, -1);
        });
    }

    /**
     * Applique (+1) ou retire (−1) les bonus de l'équipement sur le joueur. La ligne de bonus
     * est créée si elle manque (caractéristique ajoutée après l'inscription du joueur) ;
     * l'ancien code perdait silencieusement le bonus dans ce cas.
     */
    private function appliquerCaracteristiques(User $user, Equipement $equipement, int $signe): void
    {
        foreach ($equipement->getEquipementCaracteristiques() as $equipementCaracteristique) {
            $caracteristique = $equipementCaracteristique->getCaracteristique();

            $bonus = $this->joueurCaracteristiqueBonusRepository->findOneBy([
                'joueur' => $user,
                'caracteristique' => $caracteristique,
            ]);

            if ($bonus === null) {
                if ($signe < 0) {
                    continue; // rien à retirer
                }

                $bonus = new JoueurCaracteristiqueBonus();
                $bonus->setJoueur($user);
                $bonus->setCaracteristique($caracteristique);
                $bonus->setPoints(0);
                $this->entityManager->persist($bonus);
            }

            // Plancher à 0 : un bonus négatif n'a pas de sens et se propagerait au combat.
            $bonus->setPoints(max(0, $bonus->getPoints() + $signe * $equipementCaracteristique->getValeur()));
        }
    }
}
