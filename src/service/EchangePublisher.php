<?php

namespace App\service;

use App\Entity\Echange;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Diffusion temps réel des sessions d'échange via Mercure.
 *
 * Topics :
 *  - `echange/{id}` : l'état complet de la session, pour les deux participants ;
 *  - `user/{id}`    : notifications personnelles (invitation reçue).
 * Les updates sont PRIVÉS : seuls les porteurs d'un JWT subscriber listant le topic
 * les reçoivent (délivré par /api/mercure/token).
 *
 * Un échec de publication ne doit JAMAIS faire échouer l'action de jeu : la transaction
 * SQL est déjà commitée, le front peut toujours se resynchroniser via /api/echange/current.
 */
class EchangePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly EchangeNormalizer $normalizer,
        private readonly LoggerInterface $logger
    ) {}

    public function publierEtat(Echange $echange, string $type = 'echange.maj'): void
    {
        $payload = [
            'type' => $type,
            'echange' => $this->normalizer->normalize($echange),
        ];

        $this->publier(sprintf('echange/%d', $echange->getId()), $payload);

        // L'invitation doit atteindre le destinataire AVANT qu'il ne connaisse la session.
        if ($type === 'echange.invitation') {
            $this->publier(sprintf('user/%d', $echange->getJoueurDeux()->getId()), $payload);
        }
    }

    private function publier(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update(
                topics: $topic,
                data: json_encode($payload, JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Publication Mercure échouée sur {topic} : {message}', [
                'topic' => $topic,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
