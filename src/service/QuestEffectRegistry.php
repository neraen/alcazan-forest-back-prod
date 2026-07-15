<?php

namespace App\service;

use App\Config\GameContent;
use App\Entity\User;
use App\Enum\Classe;
use App\Enum\QuestEffect;
use App\Exception\QuestException;
use App\Repository\AlignementRepository;
use App\Repository\BossRecompenseRepository;
use App\Repository\ClasseRepository;
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
            QuestEffect::RECOMPENSE_BOSS => $this->recompenseBoss($params),
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
     * Annonce la récompense d'un boss (comportement historique : message
     * seulement — la distribution effective reste à implémenter).
     */
    private function recompenseBoss(array $params): array
    {
        $bossRecompenses = $this->bossRecompenseRepository->findBy(['boss' => (int)($params['bossId'] ?? 0)]);
        if ($bossRecompenses === []) {
            throw new QuestException("Effet recompense_boss mal configuré : aucun boss trouvé.");
        }

        $recompense = $bossRecompenses[0]->getRecompense();
        $messages = [];
        if ($recompense->getEquipement() !== null) {
            $messages[] = "Vous gagnez {$recompense->getEquipement()->getNom()}.";
        }
        if ($recompense->getMoney() !== null) {
            $messages[] = "Vous gagnez {$recompense->getMoney()} pièces d'or.";
        }
        if ($recompense->getExperience() !== null) {
            $messages[] = "Vous gagnez {$recompense->getExperience()} points d'expérience.";
        }

        return [
            'messages' => $messages,
            'needRefresh' => false,
        ];
    }
}
