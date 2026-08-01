<?php

namespace App\Controller;

use App\DTO\CauseMort;
use App\Entity\Friend;
use App\Enum\TypeCible;
use App\Enum\TypeCumul;
use App\Enum\TypeEvenement;
use App\Enum\TypeItem;
use App\Repository\BossRepository;
use App\Repository\ConsommableRepository;
use App\Repository\EquipementRepository;
use App\Repository\FriendRepository;
use App\Repository\MonstreCarreauRepository;
use App\Repository\SortilegeRepository;
use App\Repository\UserRepository;
use App\service\DeathService;
use App\service\CumulJoueurService;
use App\service\JournalService;
use App\service\PvpService;
use App\service\SacService;
use App\service\LevelingService;
use App\service\SpellService;
use App\service\VenteService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use App\Exception\DonjonException;
use App\Exception\PvpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;



#[Route("/api", name:"api_")]
class PlayerActionController extends AbstractController
{
    public function __construct(){}

    /**
     * Duel entre joueurs. Contrôleur FIN : toutes les règles sont dans `PvpService`.
     *
     * L'ancienne version faisait soixante-dix lignes, construisait ses messages en HTML, et
     * ne vérifiait NI les points d'action, NI la carte, NI la portée — attaquer un joueur
     * était gratuit et possible à travers le monde.
     */
    #[Route("/joueur/attack/joueur", name:"joueur_attack_joueur", methods: ["POST"])]
    public function attackPlayerVsPlayer(
        Request             $request,
        SortilegeRepository $sortilegeRepository,
        PvpService          $pvpService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $sort = $sortilegeRepository->find($data['spellId'] ?? 0);
        $cibleId = (int) ($data['targetId'] ?? 0);

        try {
            // Le type du sort aiguille : `PvpService` refuse de toute façon un sort qui
            // n'est pas de la bonne famille, mais l'aiguillage évite un aller-retour.
            $resultat = match ($sort?->getType()) {
                'soin' => $pvpService->soigner($this->getUser(), $cibleId, (int) $sort->getId()),
                'attack' => $pvpService->attaquer($this->getUser(), $cibleId, (int) $sort->getId()),
                default => throw new PvpException("Ce sortilège ne peut pas viser un joueur."),
            };
        } catch (PvpException $exception) {
            // ⚠️ Un refus DOIT se voir : le front toaste ce message. Sans lui, un clic ne
            // produirait rien de visible, ce qui se lit comme un bug de la carte.
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($resultat);
    }


    #[Route("/joueur/attack/monster", name:"joueur_attack_monster", methods: ["POST"])]
    public function attackPlayerVsMonster(
        Request                     $request,
        SortilegeRepository         $sortilegeRepository,
        MonstreCarreauRepository    $monstreCarreauRepository,
        SpellService                $spellService,
        DeathService                $deathService,
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
            $statsAfterDeath = $deathService->diePlayer($user, CauseMort::monstre((int)$target->getMonstre()->getId()));
            $newExperience['experience'] = $statsAfterDeath['experience'];
            $mapId = $statsAfterDeath['mapId'];
            $isDead = true;
            // Après la mort, la vie est celle du ressuscité : renvoyer la valeur négative
            // du coup fatal afficherait « -35/765 » dans la fiche.
            $arrayStat['lifeJoueur'] = $user->getCurrentLife();
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
        LevelingService         $levelingService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $spell = $sortilegeRepository->find($data['spellId']);
        $arrayStat = [];
        $user = $this->getUser();

        $target = $bossRepository->find($data['targetId']);

        $experience = 0;
        if($spell->getType() === "attack"){
            try{
                $arrayStat = $spellService->doDamageOnBoss($target, $spell, $user);
            }catch(DonjonException $exception){
                // Garde-fous serveur (PA, carte, portée) : message FR destiné au joueur.
                return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
            }
            $experience =  mt_rand(235, 340);
        }else{
            /** coder les buffs */
        }


        /* todo faire un calcul de l'attaque max potentielle pour l'experience */

        $newExperience = $levelingService->giveExperienceToAPlayer($experience, $user->getId());

        // La mort est jouée par SpellService, là où le coup du boss porte (il peut d'ailleurs
        // frapper un AUTRE joueur que l'attaquant). Le contrôleur ne fait plus que le
        // constater : tester `lifeJoueur <= 0` ici ne marcherait plus, la vie étant déjà
        // remise à son maximum par la mort.
        $isDead = (bool)($arrayStat['mortJoueur'] ?? false);
        if($isDead){
            $newExperience['experience'] = $arrayStat['apresMort']['experience'] ?? $newExperience['experience'];
            $mapId = $arrayStat['apresMort']['mapId'] ?? null;
        }

        $message = "Vous infligez {$arrayStat['damage']} points de dommages et vous gagnez $experience points d'expériences <br />";

        if(!($arrayStat['kill'] ?? false)){
            $message .= "{$target->getName()} vous attaque avec {$arrayStat['spell']} et vous inflige {$arrayStat['damageReturns']} points de dommage !<br />";
        }

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
            'bossLife' => $arrayStat['life'] ?? null,
            // Retour des mécaniques de donjon (zones annoncées, renforts, cible du boss) :
            // le front les affiche sans avoir à rappeler /api/donjon/combat.
            'combat' => $arrayStat['combat'] ?? null,
            'needRefresh' => ($arrayStat['kill'] ?? false) || $isDead
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
        Request                $request,
        ConsommableRepository  $consommableRepository,
        SacService             $sacService,
        EntityManagerInterface $entityManager,
    ): Response {
        $dataConsommable = json_decode($request->getContent(), true);
        $consommableEntity = $consommableRepository->find($dataConsommable['consommableId'] ?? 0);
        $user = $this->getUser();

        $message = '';
        if ($consommableEntity === null) {
            $message = "Ce consommable n'existe pas.";
        } else {
            try {
                $entityManager->wrapInTransaction(function () use ($sacService, $user, $consommableEntity, $entityManager): void {
                    // Contrôle possession ET disponible : un consommable réservé par un
                    // échange en cours n'est pas utilisable.
                    $sacService->retirerItem($user, TypeItem::CONSOMMABLE, $consommableEntity->getId(), 1);

                    if (!$consommableEntity->getIsBuff()) {
                        if ($consommableEntity->getType() === "vie") {
                            $user->setCurrentlife(min(
                                $user->getMaxLife(),
                                $user->getCurrentLife() + $consommableEntity->getPoints()
                            ));
                        } elseif ($consommableEntity->getType() === "mana") {
                            $user->setCurrentMana(min(
                                $user->getMaxMana(),
                                $user->getCurrentMana() + $consommableEntity->getPoints()
                            ));
                        }
                        $entityManager->persist($user);
                    }
                });
            } catch (\DomainException $exception) {
                $message = $exception->getMessage();
            }
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
        \App\Repository\PnjRepository            $pnjRepository,
        \App\Repository\ShopEquipementRepository $shopEquipementRepository,
        SacService                      $sacService,
        JournalService                  $journalService,
        CumulJoueurService              $cumulJoueurService,
        EntityManagerInterface          $entityManager,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $idEquipement = $data['item'] ?? null;
        $equipementEntity = $idEquipement !== null ? $equipementRepository->find($idEquipement) : null;
        $user = $this->getUser();

        if ($equipementEntity === null) {
            return new JsonResponse([
                'money' => $user->getMoney(),
                'error' => "Cet objet n'est plus en vente."
            ], Response::HTTP_BAD_REQUEST);
        }

        // Prix effectif : prix fixé en boutique (shop_equipement.prix) si dispo,
        // sinon prix d'achat de base de l'équipement.
        $price = $equipementEntity->getPrixAchat();
        $pnjId = $data['pnjId'] ?? null;
        if ($pnjId !== null) {
            $shop = $pnjRepository->find($pnjId)?->getShop();
            if ($shop !== null) {
                $line = $shopEquipementRepository->findOneBy(['shop' => $shop, 'equipement' => $equipementEntity]);
                if ($line !== null && $line->getPrix() !== null) {
                    $price = $line->getPrix();
                }
            }
        }
        try {
            // Débit + ajout au sac dans UNE transaction ; le débit contrôle l'or DISPONIBLE
            // (solde moins l'or réservé par un échange en cours).
            $entityManager->wrapInTransaction(function () use ($sacService, $journalService, $cumulJoueurService, $user, $equipementEntity, $price, $pnjId): void {
                $sacService->debiterOr($user, $price);
                $sacService->ajouterItem($user, TypeItem::EQUIPEMENT, $equipementEntity->getId(), 1);
                $cumulJoueurService->ajouter($user, TypeCumul::OR_DEPENSE, $price);

                // Consigné DANS la transaction : si le débit échoue, l'achat n'a pas eu lieu
                // et le journal ne doit pas prétendre le contraire. C'est ce que garantit
                // l'INSERT natif de JournalService, qui emprunte la même connexion.
                //
                // Exceptionnellement écrit depuis un contrôleur : l'achat en échoppe n'a pas
                // de service (`ShopService` est une coquille vide et le vider ici serait un
                // refactoring hors sujet). La vente, elle, passe bien par `VenteService`.
                $journalService->consigner(
                    type: TypeEvenement::ACHAT_PNJ,
                    acteur: $user,
                    cibleType: TypeCible::EQUIPEMENT,
                    cibleId: (int) $equipementEntity->getId(),
                    quantite: 1,
                    montantOr: $price,
                    contexte: [
                        'pnjId' => $pnjId,
                        'items' => [[
                            'type' => TypeItem::EQUIPEMENT->value,
                            'id' => (int) $equipementEntity->getId(),
                            'quantite' => 1,
                            'nom' => $equipementEntity->getNom(),
                        ]],
                    ],
                );
            });
        } catch (\DomainException $exception) {
            return new JsonResponse([
                'money' => $user->getMoney(),
                'error' => "Vous n'avez pas assez d'or pour acheter cet objet."
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'money' => $user->getMoney(),
            'prix' => $price,
            'nomEquipement' => $equipementEntity->getNom(),
            'message' => sprintf('%s acheté pour %d pièces d\'or.', $equipementEntity->getNom(), $price)
        ]);
    }


    /**
     * Vente d'un item du sac au marchand. Le prix encaissé est celui de l'item (0 si le
     * contenu n'en définit pas) : le client n'envoie jamais de montant.
     */
    #[Route("/joueur/sell/shop", name:"joueur_sell_shop", methods: ["POST"])]
    public function playerSellItem(
        Request      $request,
        VenteService $venteService
    ): Response {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();

        $type = TypeItem::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            return new JsonResponse([
                'money' => $user->getMoney(),
                'error' => "Type d'objet inconnu."
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $vente = $venteService->sell(
                $user,
                $type,
                (int) ($data['id'] ?? 0),
                (int) ($data['quantite'] ?? 1)
            );
        } catch (\DomainException $exception) {
            return new JsonResponse([
                'money' => $user->getMoney(),
                'error' => $exception->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }

        $pluriel = $vente['quantite'] > 1;
        $intitule = $pluriel
            ? sprintf('%d × %s', $vente['quantite'], $vente['nom'])
            : $vente['nom'];

        return new JsonResponse([
            'money' => $vente['money'],
            'prix' => $vente['prix'],
            'prixUnitaire' => $vente['prixUnitaire'],
            'quantite' => $vente['quantite'],
            'nom' => $vente['nom'],
            'message' => $vente['prix'] > 0
                ? sprintf('%s %s pour %d pièces d\'or.', $intitule, $pluriel ? 'vendus' : 'vendu', $vente['prix'])
                : sprintf('%s %s — le marchand n\'en donne rien.', $intitule, $pluriel ? 'cédés' : 'cédé')
        ]);
    }


    #[Route("/chat/init", name:"chat_init", methods: ["POST"])]
    public function initChat(){
        //$chat = new ChatService();
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