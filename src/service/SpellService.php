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
use App\Repository\SortilegeRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserBuffRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class SpellService
{
    public $deathService;

    private $caracteristiqueService;
    public $joueurCaracteristiqueRepository;
    public $joueurCaracteristiqueBonusRepository;
    public $niveauJoueurRepository;
    public $security;
    public $userRepository;
    public $bossRepository;
    public $userBossRepository;
    public $userBuffRepository;
    public $buffCaracteristiqueRepository;
    public $entityManager;

    const DAMAGE_BALANCING_CONSTANT = 400;
    const MAX_ARMOR_REDUCTION = 0.4;
    const BASE_ARMOR_COEF = 2.2;

    public function __construct(
        DeathService $deathService,
        CaracteristiqueService $caracteristiqueService,
        JoueurCaracteristiqueRepository $joueurCaracteristiqueRepository,
        JoueurCaracteristiqueBonusRepository $joueurCaracteristiqueBonusRepository,
        NiveauJoueurRepository $niveauJoueurRepository,
        Security $security,
        UserRepository $userRepository,
        BossRepository $bossRepository,
        BossSortilegeRepository $bossSortilegeRepository,
        UserBossRepository $userBossRepository,
        UserBuffRepository $userBuffRepository,
        BuffCaracteristiqueRepository $buffCaracteristiqueRepository,
        EntityManagerInterface $entityManager
    )
    {
        $this->deathService = $deathService;
        $this->caracteristiqueService = $caracteristiqueService;
        $this->joueurCaracteristiqueRepository = $joueurCaracteristiqueRepository;
        $this->joueurCaracteristiqueBonusRepository = $joueurCaracteristiqueBonusRepository;
        $this->niveauJoueurRepository = $niveauJoueurRepository;
        $this->security = $security;
        $this->userRepository = $userRepository;
        $this->bossRepository = $bossRepository;
        $this->bossSortilegeRepository = $bossSortilegeRepository;
        $this->userBossRepository = $userBossRepository;
        $this->userBuffRepository = $userBuffRepository;
        $this->buffCaracteristiqueRepository = $buffCaracteristiqueRepository;
        $this->entityManager = $entityManager;
    }

    public function egal1(){
        return 1;
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

        $life = $target->getActualLife() - $damageStat['damage'];

        if($life <= 0){
            $killMessage = "Vous avez terrassé  {$target->getName()}.";
            $userBossEntity =$this->userBossRepository->findOneBy(['boss' => $target->getId()]);
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

            /** todo: coder drop equipement boss */

        }else{
            $this->bossRepository->updateBossLife($target->getId(), $life);
        }

        $bossLifePercent = floor($target->getActualLife() / $target->getMaxLife() * 100);
        $bossSpell = $this->bossSortilegeRepository->getBossSpellByLifePercent($target->getId(), $bossLifePercent);
        $armureJoueur = $this->caracteristiqueService->getPlayerArmor($user);
        $damageReturns =  $bossSpell['degatBase'] + floor(mt_rand($target->getPuissance() * $bossSpell['coefSecondaire'],$target->getPuissance() * $bossSpell['coefPrincipal'])) - ($armureJoueur * 0.2);

        $lifeJoueurAfterReturns = $user->getCurrentLife() - $damageReturns;
        $user->setCurrentLife($lifeJoueurAfterReturns);
        $pointActionRestant = $user->getActionPoint() - $spell->getPointAction();
        $user->setActionPoint($pointActionRestant);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return ['life' => $life, 'lifeJoueur' => $lifeJoueurAfterReturns,'damage' => $damageStat['damage'], 'damageReturns' => $damageReturns, 'killMessage' => $killMessage ?? null, 'spell' => $bossSpell['name']];
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
        $armureJoueur = 30;
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
            $userBuffEntity = new UserBuff();
            $userBuffEntity->setUser($target);
            $userBuffEntity->setBuff($buff);
            $datetimeNow = new \DateTime('now');
            $userBuffEntity->setDateDebut(new \DateTime('now'));
            $userBuffEntity->setDateFin($datetimeNow->modify('+'.$buff->getDuree().' seconds'));

            $this->entityManager->persist($userBuffEntity);
            $this->entityManager->flush();
        }

        return true;
    }

    public function playerCanBeBuffed(Buff $buff, User $user): bool{
        $isPlayerBuffed = $this->userBuffRepository->findOneBy(['buff' => $buff->getId(), 'user' => $user->getId()]);
        $allBuffsInPlayer = $this->userBuffRepository->findBy(['user' => $user]);
        return $isPlayerBuffed && count($allBuffsInPlayer) < 3;
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