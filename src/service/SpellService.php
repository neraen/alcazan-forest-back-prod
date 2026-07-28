<?php

namespace App\service;

use App\Entity\Boss;
use App\Entity\Buff;
use App\Entity\MonstreCarreau;
use App\Entity\Sortilege;
use App\Entity\User;
use App\Entity\UserBoss;
use App\Entity\UserBuff;
use App\Repository\BossRepository;
use App\Repository\BossSortilegeRepository;
use App\Repository\BuffCaracteristiqueRepository;
use App\Repository\JoueurCaracteristiqueBonusRepository;
use App\Repository\JoueurCaracteristiqueRepository;
use App\Repository\NiveauJoueurRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserBuffRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;


class SpellService
{
    const DAMAGE_BALANCING_CONSTANT = 400;
    const MAX_ARMOR_REDUCTION = 0.4;
    const BASE_ARMOR_COEF = 2.2;

    public function __construct(
        private DeathService $deathService,
        private CaracteristiqueService $caracteristiqueService,
        private JoueurCaracteristiqueRepository $joueurCaracteristiqueRepository,
        private JoueurCaracteristiqueBonusRepository $joueurCaracteristiqueBonusRepository,
        private NiveauJoueurRepository $niveauJoueurRepository,
        private UserRepository $userRepository,
        private BossRepository $bossRepository,
        private BossSortilegeRepository $bossSortilegeRepository,
        private UserBossRepository $userBossRepository,
        private UserBuffRepository $userBuffRepository,
        private BuffCaracteristiqueRepository $buffCaracteristiqueRepository,
        private DonjonInstanceService $donjonInstanceService,
        private DonjonCombatService $donjonCombatService,
        private EntityManagerInterface $entityManager
    ){
    }

    public function doDamage(User $target, Sortilege $spell, User $user){

        $caracteristiques = $this->getCaracsForSpell($user, $spell);

        $damageStat = [];
        $armorPoints = $this->caracteristiqueService->getPlayerArmor($target); // Points d'armure du défenseur
        $spellDamage =  $this->getSpellDamageByCarac($spell, $caracteristiques['principale'], $caracteristiques['secondaire']);
        $damageStat['damage'] = $this->computeDamageWithArmor($armorPoints, $spellDamage);

        $life = $target->getCurrentLife() - $damageStat['damage'];

        if($life <= 0){
            $this->deathService->diePlayer($target);
            $targetLevel = $this->niveauJoueurRepository->getPlayerLevel($target->getId());
            $playerLevel = $this->niveauJoueurRepository->getPlayerLevel($user->getId());
            $honnorGain = $this->computeHonnorGain($user, $targetLevel, $playerLevel);
            $honnorLoose = $this->computeHonnorLoose($target, $targetLevel, $playerLevel);
            $damageStat['kill'] = true;
            $damageStat['honor'] = $honnorGain;
            $damageStat['honorLoose'] = $honnorLoose;
        }else{
            $this->userRepository->updateTargetLife($target, $life);
            if(!is_null($spell->getBuff())){
                $this->applyBuffEffect($target,  $spell);
            }
        }

        return $damageStat;
    }

    /**Calcul les dommages qu'un joueur inflige au boss */
    public function doDamageOnBoss(Boss $target, Sortilege $spell, User $user){
        $caracteristiques = $this->getCaracsForSpell($user, $spell);

        $damageStat = [];
        $armureJoueur = 0;
        $damageStat['damage'] = $this->getSpellDamageByCarac($spell, $caracteristiques['principale'], $caracteristiques['secondaire']) - ($armureJoueur * 0.2);

        // En donjon, la vie du boss appartient à l'INSTANCE : `boss.actual_life` reste la
        // valeur des boss de plein air, partagée par tout le serveur.
        $instance = $this->donjonInstanceService->instancePourBoss($user, $target);

        // Garde-fous SERVEUR (PA, carte, portée) : sans eux, la position n'a aucun poids
        // et les mécaniques de déplacement du donjon ne veulent rien dire.
        $this->donjonCombatService->verifierAttaqueBoss($user, $target, $spell);

        $vieAvant = $instance !== null
            ? $this->donjonInstanceService->vieBoss($instance, $target)
            : $target->getActualLife();

        $life = $vieAvant - $damageStat['damage'];

        $kill = $life <= 0;
        if($kill){
            $life = 0;
            $killMessage = "Vous avez terrassé  {$target->getName()}.";
            $userBossEntity = $this->userBossRepository->findOneBy(['boss' => $target->getId(), 'user' => $user->getId()]);
            if(!is_null($userBossEntity)){
                $userBossEntity->setLastKill(new \DateTime('now'));
                $userBossEntity->setNumberKill($userBossEntity->getNumberKill() + 1);
                $this->entityManager->persist($userBossEntity);
                $this->entityManager->flush();
            }else{
                $userBossEntity = new UserBoss();
                $userBossEntity->setUser($user);
                $userBossEntity->setBoss($target);
                $userBossEntity->setLastKill(new \DateTime('now'));
                $userBossEntity->setNumberKill(1);
                $this->entityManager->persist($userBossEntity);
                $this->entityManager->flush();
            }

            if($instance !== null){
                // L'instance passe TERMINEE : le groupe garde l'accès à la salle au
                // trésor mais ne peut plus recombattre le boss avec le même verrou.
                $this->donjonInstanceService->enregistrerVieBoss($instance, 0);
            }else{
                // `boss.actual_life` est une colonne GLOBALE (partagée par tout le serveur) :
                // sans remise à zéro, un boss de plein air reste mort pour tout le monde.
                $this->bossRepository->updateBossLife($target->getId(), $target->getMaxLife());
            }
        }else{
            if($instance !== null){
                $this->donjonInstanceService->enregistrerVieBoss($instance, $life);
            }else{
                $this->bossRepository->updateBossLife($target->getId(), $life);
            }
        }

        $pointActionRestant = $user->getActionPoint() - $spell->getPointAction();
        $user->setActionPoint($pointActionRestant);

        // Un boss terrassé ne riposte pas.
        if($kill){
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return ['life' => 0, 'lifeJoueur' => $user->getCurrentLife(), 'damage' => $damageStat['damage'],
                    'damageReturns' => 0, 'killMessage' => $killMessage, 'spell' => null, 'kill' => true,
                    'combat' => ['messages' => [], 'zones' => []]];
        }

        // Phase du boss : calculée sur la vie RESTANTE (et non sur la valeur d'avant le coup).
        $bossLifePercent = floor($life / $target->getMaxLife() * 100);
        $bossSpell = $this->bossSortilegeRepository->getBossSpellByLifePercent($target->getId(), $bossLifePercent);
        $armureJoueur = $this->caracteristiqueService->getPlayerArmor($user);
        $damageReturns =  $bossSpell['degatBase'] + floor(mt_rand($target->getPuissance() * $bossSpell['coefSecondaire'],$target->getPuissance() * $bossSpell['coefPrincipal'])) - ($armureJoueur * 0.2);

        $combat = ['messages' => [], 'zones' => []];
        if($instance !== null){
            // Menace d'abord : la riposte doit viser la cible D'APRÈS ce coup-ci.
            $this->donjonCombatService->engagerCombat($instance);
            $this->donjonCombatService->ajouterMenaceDegats($instance, $user, (int)$damageStat['damage']);
            $this->entityManager->flush();

            $damageReturns = (int)round($damageReturns * $this->donjonCombatService->multiplicateurEnrage($instance));

            // Le boss frappe la plus grosse menace, pas forcément l'attaquant : c'est ce
            // qui fait exister le rôle de tank. Restreint à SA salle (on lui passe le boss) :
            // il continuait sinon de frapper un joueur reparti dans une autre salle.
            $cible = $this->donjonCombatService->cibleDuBoss($instance, $target);
            $victime = $cible?->getUser() ?? $user;
            $victime->setCurrentLife($victime->getCurrentLife() - $damageReturns);
            $this->entityManager->persist($victime);
            $this->entityManager->flush();

            // Le coup du boss peut TUER, y compris un autre joueur que l'attaquant : sans
            // ça la victime restait en vie négative sur place, toujours ciblée.
            // `diePlayer` écrit en DQL — donc après le flush ci-dessus, jamais avant.
            $victimeMorte = $victime->getCurrentLife() <= 0;
            $apresMort = $victimeMorte ? $this->deathService->diePlayer($victime) : null;

            $combat = $this->donjonCombatService->jouerTick($instance, $target);
            $combat['cible'] = $victime->getPseudo();

            return ['life' => $life, 'lifeJoueur' => $user->getCurrentLife(), 'damage' => $damageStat['damage'],
                    'damageReturns' => $victime->getId() === $user->getId() ? $damageReturns : 0,
                    'killMessage' => null, 'spell' => $bossSpell['name'], 'kill' => false, 'combat' => $combat,
                    // La mort est jouée ICI (c'est ici que le coup porte) : le contrôleur
                    // n'a plus qu'à en tirer le message et la carte d'arrivée.
                    'mortJoueur' => $victimeMorte && $victime->getId() === $user->getId(),
                    'apresMort' => $apresMort];
        }

        $lifeJoueurAfterReturns = $user->getCurrentLife() - $damageReturns;
        $user->setCurrentLife($lifeJoueurAfterReturns);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $mortJoueur = $lifeJoueurAfterReturns <= 0;
        $apresMort = $mortJoueur ? $this->deathService->diePlayer($user) : null;

        return ['life' => $life, 'lifeJoueur' => $user->getCurrentLife(), 'damage' => $damageStat['damage'],
                'damageReturns' => $damageReturns, 'killMessage' => null, 'spell' => $bossSpell['name'],
                'kill' => false, 'combat' => $combat,
                'mortJoueur' => $mortJoueur, 'apresMort' => $apresMort];
    }

    public function computeHonnorGain(User $user, int $targetLevel, int $playerLevel){
        $difference = $playerLevel - $targetLevel;
        $honnor = 0;
        if($difference > 50){
            $honnor = -5;
        }else if ($difference < 30 && $difference > 18){
            $honnor = floor((1/$difference) * 100);
        }else if($difference < 18 && $difference > 9) {
            $honnor = floor((1.5/$difference) * 100);
        }else if($difference < 10 && $difference > -9){
            $honnor = floor((35 - (18-($difference+9)) * 0.8));
        }else if($difference < -9 && $difference > -30){
            $honnor = 50 - 30 - $difference;
        }else{
            $honnor = 50;
        }

        $newHonnor = $user->getHonneur() + $honnor;
        $this->userRepository->updatePlayerHonnor($user->getId(), $newHonnor);

        return $honnor;
    }

    public function computeHonnorLoose(User $target, int $targetLevel, int $playerLevel){
        $difference = $targetLevel - $playerLevel;
        $honnor = 0;
        if($difference > 50){
            $honnor = -25;
        }else if ($difference < 30 && $difference > 18){
            $honnor = -20;
        }else if($difference < 18 && $difference > 9) {
            $honnor =-15;
        }else if($difference < 10 && $difference > -9){
            $honnor = -10;
        }else if($difference < -9 && $difference > -30){
            $honnor = -5;
        }else{
            $honnor = 0;
        }

        $newHonnor = $target->getHonneur() + $honnor;
        $this->userRepository->updatePlayerHonnor($target->getId(), $newHonnor);

        return $honnor;
    }

    public function healPlayer(User $target, Sortilege $spell, User $user){
        $caracteristiques = $this->getCaracsForSpell($user, $spell);

        $damageStat = [];
        $damageStat['value'] = $this->getSpellDamageByCarac($spell, $caracteristiques['principale'], $caracteristiques['secondaire']);

        $lastLifePoint = $target->getCurrentLife();
        $life = $lastLifePoint + $damageStat['value'];

        if($life > $target->getMaxLife()){
            $life = $target->getMaxLife();
            $this->userRepository->updateTargetLife($target, $life);
            $damageStat['life'] = $life;
            $damageStat['value'] = $target->getMaxLife() - $lastLifePoint;
        }else{
            $this->userRepository->updateTargetLife($target, $life);
            $damageStat['life'] = $life;
        }

        return $damageStat;
    }

    public function doDamageOnMonster(MonstreCarreau $target, Sortilege $spell, User $user): array{
        $caracteristiques = $this->getCaracsForSpell($user, $spell);

        $damage = $this->getSpellDamageByCarac($spell, $caracteristiques['principale'], $caracteristiques['secondaire']);
        $life = $target->getCurrentLife() - $damage;

        $puissanceMonstre = $target->getMonstre()->getPuissance();
        $armureJoueur = $this->caracteristiqueService->getPlayerArmor($user);
        $damageReturns =  floor(mt_rand($puissanceMonstre,$puissanceMonstre * 2.2)) - ($armureJoueur * 0.2);

        $lifeJoueurAfterReturns = $user->getCurrentLife() - $damageReturns;
        $user->setCurrentLife($lifeJoueurAfterReturns);
        $pointActionRestant = $user->getActionPoint() - $spell->getPointAction();
        $user->setActionPoint($pointActionRestant);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return ['life' => $life, 'lifeJoueur' => $lifeJoueurAfterReturns,'damage' => $damage, 'damageReturns' => $damageReturns];
    }

    public function applyBuffEffect(User $target, Sortilege $spell): bool{
        $buff = $spell->getBuff();
        if(!$this->playerCanBeBuffed($buff, $target)){
            return false;
        }

        $userBuffEntity = new UserBuff();
        $userBuffEntity->setUser($target);
        $userBuffEntity->setBuff($buff);
        $datetimeNow = new \DateTime('now');
        $userBuffEntity->setDateDebut(new \DateTime('now'));
        $userBuffEntity->setDateFin($datetimeNow->modify('+'.$buff->getDuree().' seconds'));

        $this->entityManager->persist($userBuffEntity);
        $this->entityManager->flush();

        return true;
    }

    public function playerCanBeBuffed(Buff $buff, User $user): bool{
        $isPlayerBuffed = $this->userBuffRepository->findOneBy(['buff' => $buff->getId(), 'user' => $user->getId()]);
        $allBuffsInPlayer = $this->userBuffRepository->findBy(['user' => $user]);
        return $isPlayerBuffed === null && count($allBuffsInPlayer) < 3;
    }

    /** Dégâts bruts d'un sort lancé par ce joueur (avant armure de la cible). */
    public function getSpellDamageForUser(User $user, Sortilege $spell): int{
        $caracteristiques = $this->getCaracsForSpell($user, $spell);

        return $this->getSpellDamageByCarac($spell, $caracteristiques['principale'], $caracteristiques['secondaire']);
    }

    public function getCaracsForSpell(User $user, Sortilege $spell): array{
        $principale = $this->joueurCaracteristiqueRepository->findOneBy(['user' => $user->getId(),
            'caracteristique' => $spell->getCaracteristiqueDegat()])->getPoints();
        $principaleBonus = $this->joueurCaracteristiqueBonusRepository->findOneBy(['caracteristique' => $spell->getCaracteristiqueDegat(), 'joueur' => $user->getId()])->getPoints();
        $principaleBuff = $this->getCaracBuffed($spell->getCaracteristiqueDegat(), $user->getId());

        $secondaire = $this->joueurCaracteristiqueRepository->findOneBy(['user' => $user->getId(),
            'caracteristique' => $spell->getCaracteristiqueEquilibre()])->getPoints();
        $secondaireBonus = $this->joueurCaracteristiqueBonusRepository->findOneBy(['caracteristique' => $spell->getCaracteristiqueEquilibre(), 'joueur' => $user->getId()])->getPoints();
        $secondaireBuff = $this->getCaracBuffed($spell->getCaracteristiqueEquilibre(), $user->getId());

        $principale = $principale + $principaleBonus + $principaleBuff;
        $secondaire = $secondaire + $secondaireBonus + $secondaireBuff;
        return [
            'principale' => $principale,
            'secondaire' => $secondaire
        ];
    }

    public function getCaracBuffed(int $idCaracteristique, int $userId): int{
        $buffs = $this->userBuffRepository->getAllBuffCaracteristiqueId($userId);
        $buffValue = 0;
        foreach ($buffs as $buff){
            $haveBuffCarac = $this->buffCaracteristiqueRepository->getValueByBuffAndCarac($buff['id'], $idCaracteristique);
            if(count($haveBuffCarac) > 0){
                $buffValue += $haveBuffCarac[0]['value'];
            }
        }
        return $buffValue;
    }

    public function getSpellDamageByCarac(Sortilege $spell, int $caracPrincipale, int $caracSecondaire): int{
        $minimal = $spell->getDegatBase() + $caracSecondaire * $spell->getCoefSecondaire();
        $maximal = $spell->getDegatBase() + $caracPrincipale * $spell->getCoefPrincipal();

        if($minimal >= $maximal){
            $minimal = $maximal-20;
        }

        return floor(mt_rand($minimal, $maximal));
    }

    public function computeDamageWithArmor(int $armorPoints, int $spellDamage){
        $damageReduction = (1 - pow(self::BASE_ARMOR_COEF, -$armorPoints / self::DAMAGE_BALANCING_CONSTANT)) * self::MAX_ARMOR_REDUCTION;
        return $spellDamage * (1 - $damageReduction);
    }


}