<?php

namespace App\service;

use App\Config\GameContent;
use App\Entity\User;
use App\Enum\Classe;
use App\Enum\QuestEffect;
use App\Exception\DonjonException;
use App\Exception\QuestException;
use App\Repository\AlignementRepository;
use App\Repository\BossRecompenseRepository;
use App\Repository\CarteCarreauRepository;
use App\Repository\ClasseRepository;
use App\Repository\UserBossRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whitelist serveur des effets scriptés des actions SCRIPTED_EFFECT
 * (et des actions de case). Remplace l'ancien mécanisme api_link/params
 * où des URLs arbitraires étaient stockées en base et POSTées par le front.
 *
 * Retour d'execute() : ['messages' => string[], 'needRefresh' => bool].
 */
class QuestEffectRegistry
{
    public function __construct(
        private readonly ClasseRepository $classeRepository,
        private readonly UserRepository $userRepository,
        private readonly AlignementRepository $alignementRepository,
        private readonly BossRecompenseRepository $bossRecompenseRepository,
        private readonly UserBossRepository $userBossRepository,
        private readonly CarteCarreauRepository $carteCarreauRepository,
        private readonly DonjonInstanceService $donjonInstanceService,
        private readonly DonjonCombatService $donjonCombatService,
        private readonly DonjonSalleService $donjonSalleService,
        private readonly RecompenseService $recompenseService,
        private readonly InventaireService $inventaireService,
        private readonly AubergeService $aubergeService,
        private readonly EntityManagerInterface $entityManager
    ){}

    public function execute(QuestEffect $effect, array $params, User $user): array
    {
        return match ($effect) {
            QuestEffect::CHOISIR_CLASSE => $this->choisirClasse($params, $user),
            QuestEffect::CHOISIR_ALIGNEMENT => $this->choisirAlignement($params, $user),
            QuestEffect::ENTRER_AUBERGE => $this->entrerAuberge($user),
            QuestEffect::RECOMPENSE_BOSS => $this->recompenseBoss($params, $user),
            QuestEffect::ACTIONNER_LEVIER => $this->actionnerLevier($params, $user),
        };
    }

    /** Assigne la classe choisie et donne l'équipement de départ correspondant. */
    private function choisirClasse(array $params, User $user): array
    {
        $classe = Classe::tryFrom($params['classe'] ?? '');
        if ($classe === null) {
            throw new QuestException("Effet choisir_classe mal configuré : classe inconnue.");
        }

        $classeEntity = $this->classeRepository->findOneBy(['nom' => $classe->value]);
        if ($classeEntity === null) {
            throw new QuestException("La classe {$classe->value} n'existe pas en base.");
        }

        $this->userRepository->updateClasse($classeEntity->getId(), $user->getId());

        $startingEquipementId = match ($classe) {
            Classe::ARCHER => GameContent::STARTING_EQUIPEMENT_ARCHER,
            Classe::SORCIER => GameContent::STARTING_EQUIPEMENT_SORCIER,
            Classe::GUERRIER => GameContent::STARTING_EQUIPEMENT_GUERRIER,
            Classe::MOINE => GameContent::STARTING_EQUIPEMENT_MOINE,
        };
        $this->inventaireService->addEquipementToUserInventaire($user->getId(), $startingEquipementId);

        return [
            'messages' => ["Vous êtes maintenant {$classe->value}. Votre équipement de départ vous attend dans votre inventaire."],
            'needRefresh' => true,
        ];
    }

    private function choisirAlignement(array $params, User $user): array
    {
        $alignement = $this->alignementRepository->find((int)($params['alignement'] ?? 0));
        if ($alignement === null) {
            throw new QuestException("Effet choisir_alignement mal configuré : alignement inconnu.");
        }

        $user->setAlignement($alignement);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return [
            'messages' => ["Vous rejoignez l'alignement {$alignement->getNom()}."],
            'needRefresh' => true,
        ];
    }

    private function entrerAuberge(User $user): array
    {
        return [
            'messages' => [$this->aubergeService->entrer($user)],
            'needRefresh' => true,
        ];
    }

    /**
     * Coffre de la salle au trésor : tire dans la table de butin du boss (pondérée
     * par `boss_recompense.taux`) et distribue réellement au joueur.
     *
     * Trois garde-fous, car la case action est cliquable à volonté :
     *  - il faut avoir tué le boss ;
     *  - la mise à mort doit dater de moins de FENETRE_SALLE_TRESOR_SECONDES ;
     *  - un seul ramassage par mise à mort (UserBoss::butinDisponible).
     *
     * L'appelant (QuestProgressionService) fournit la transaction.
     */
    private function recompenseBoss(array $params, User $user): array
    {
        $bossId = (int)($params['bossId'] ?? 0);
        $bossRecompenses = $this->bossRecompenseRepository->findBy(['boss' => $bossId]);
        if ($bossRecompenses === []) {
            throw new QuestException("Effet recompense_boss mal configuré : aucun boss trouvé.");
        }

        $userBoss = $this->userBossRepository->findOneBy(['user' => $user->getId(), 'boss' => $bossId]);
        if ($userBoss === null || !$userBoss->butinDisponible()) {
            throw new QuestException("Ce coffre est vide. Terrassez de nouveau le gardien pour qu'il se remplisse.");
        }

        $age = (new \DateTime('now'))->getTimestamp() - $userBoss->getLastKill()->getTimestamp();
        if ($age >= GameContent::FENETRE_SALLE_TRESOR_SECONDES) {
            throw new QuestException("Le trésor s'est volatilisé : vous avez trop tardé après votre victoire.");
        }

        $recompense = $this->recompenseService->tirerDansTable($bossRecompenses);

        $userBoss->setLastLoot(new \DateTime('now'));
        $this->entityManager->persist($userBoss);

        if ($recompense === null) {
            return [
                'messages' => ["Le coffre ne contenait que de la poussière."],
                'needRefresh' => false,
            ];
        }

        ['rewards' => $rewards] = $this->recompenseService->distribuer($user, $recompense);

        return [
            'messages' => $this->recompenseService->decrireRecompenses($rewards),
            'needRefresh' => true,
        ];
    }

    /**
     * Levier d'énigme de donjon. Le levier est une case action ordinaire : on réutilise
     * la machinerie des quêtes (proximité déjà vérifiée par QuestProgressionService)
     * plutôt que d'inventer un type de case.
     *
     * DonjonCombatService décide si l'énigme est résolue ; ici on ne fait que router,
     * et appliquer aux dégâts du boss le seul point de mutation qui existe pour ça.
     */
    private function actionnerLevier(array $params, User $user): array
    {
        $instance = $this->donjonInstanceService->instanceCourante($user);
        if ($instance === null) {
            throw new QuestException("Ce levier ne fonctionne qu'à l'intérieur du donjon.");
        }

        // `carteCarreauId` est injecté par QuestProgressionService : c'est la case cliquée.
        $carteCarreauId = (int)($params['carteCarreauId'] ?? 0);
        if ($carteCarreauId === 0) {
            throw new QuestException("Effet actionner_levier mal configuré : levier introuvable.");
        }

        /* Un même levier peut commander DEUX choses : une porte de salle (condition
           LEVIERS de la salle suivante) et l'énigme de combat du boss. L'ORDRE COMPTE :
           on enregistre le geste, on regarde d'ABORD la porte, puis l'énigme de combat —
           celle-ci consomme les leviers en se résolvant, et passerait sinon devant la
           porte, qui ne verrait plus rien. */
        $this->donjonCombatService->enregistrerLevier($instance, $user, $carteCarreauId);

        $porte = $this->donjonSalleService->resoudreLeviersDeLaPorte($instance, $user);

        try {
            $resultat = $this->donjonCombatService->actionnerLevier($instance, $user, $carteCarreauId);
        } catch (DonjonException $exception) {
            // Pas de mécanique d'énigme sur ce donjon : le levier peut tout de même
            // commander une porte, ce n'est donc pas une erreur.
            $resultat = ['messages' => [], 'resolue' => false, 'degatsBoss' => 0];
        }

        if ($resultat['resolue'] && $resultat['degatsBoss'] > 0) {
            // La vie de départ doit venir de vieBoss() : `bossCurrentLife` vaut null tant
            // que le boss n'a pas été engagé, et retrancher les dégâts à 0 le tuerait
            // sur-le-champ — l'énigme aurait terminé l'expédition avant le combat.
            $boss = $this->donjonInstanceService->bossDeLInstance($instance);
            if ($boss !== null) {
                $vie = $this->donjonInstanceService->vieBoss($instance, $boss) - $resultat['degatsBoss'];
                $this->donjonInstanceService->enregistrerVieBoss($instance, max(0, $vie));
                $this->entityManager->flush();
            }
        }

        $messages = [];
        if ($porte !== null) {
            $messages[] = $porte;
        }
        $messages = array_merge($messages, $resultat['messages']);
        if ($messages === []) {
            $messages[] = "Le levier bascule dans un cliquetis, mais rien ne bouge encore.";
        }

        return [
            'messages' => $messages,
            'needRefresh' => $resultat['resolue'] || $porte !== null,
        ];
    }
}
