<?php

namespace App\service;

use App\Entity\Pnj;
use App\Entity\User;
use App\Repository\GuildeRepository;
use App\Repository\JoueurGuildeRepository;
use App\Repository\SequenceRepository;

/**
 * Construit la réponse de POST /api/pnj/interaction : lecture pure, aucun
 * effet de bord (l'ancien /api/pnj démarrait les quêtes à la consultation).
 * La vue est discriminée par "view" : quest | dialogue | shop | guilde.
 */
class PnjInteractionService
{
    public function __construct(
        private readonly PnjService $pnjService,
        private readonly QuestProgressionService $questProgressionService,
        private readonly SequenceRepository $sequenceRepository,
        private readonly GuildeRepository $guildeRepository,
        private readonly JoueurGuildeRepository $joueurGuildeRepository
    ){}

    public function getInteraction(User $user, Pnj $pnj): array
    {
        $payload = [
            'pnj' => [
                'id' => $pnj->getId(),
                'name' => $pnj->getName(),
                'avatar' => $pnj->getAvatar(),
                'description' => $pnj->getDescription(),
                'type' => $pnj->getType(),
            ],
        ];

        switch ($pnj->getType()) {
            case 'shop':
                $payload['view'] = 'shop';
                $payload['shop'] = $this->pnjService->getPnjShop($pnj);
                break;
            case 'quest':
                $payload['view'] = 'quest';
                $payload['quest'] = $this->questProgressionService->getQuestStatusForPnj($user, $pnj);
                break;
            case 'guilde':
                $payload['view'] = 'guilde';
                $payload['guilde'] = $this->buildGuildePayload($user, $pnj);
                break;
            case 'action':
            default:
                $payload['view'] = 'dialogue';
                $payload['dialogue'] = $this->buildDialoguePayload($pnj);
                break;
        }

        return $payload;
    }

    /** Dialogue autonome d'un PNJ "action" : sa séquence sans quête. */
    private function buildDialoguePayload(Pnj $pnj): ?array
    {
        $sequence = $this->sequenceRepository->findOneBy(['pnj' => $pnj, 'quete' => null]);

        return $this->questProgressionService->buildStepPayload($sequence);
    }

    /** Registre des guildes : comportement repris de l'ancien /api/pnj/guildes. */
    private function buildGuildePayload(User $user, Pnj $pnj): array
    {
        $sequence = $this->sequenceRepository->findOneBy(['pnj' => $pnj, 'quete' => null]);

        if ($user->getAlignement() === null) {
            return [
                'dialogue' => "Vous devez appartenir à un Alignement, pour pouvoir rejoindre une guilde",
                'guildes' => [],
            ];
        }

        if ($this->joueurGuildeRepository->findOneBy(['user' => $user->getId()]) !== null) {
            return [
                'dialogue' => "Vous faites déjà parti d'une guilde .",
                'guildes' => [],
            ];
        }

        return [
            'dialogue' => $sequence?->getDialogueContenu() ?? '',
            'guildes' => $this->guildeRepository->getAllGuildesForPlayer($user->getAlignement()->getId()),
        ];
    }
}
