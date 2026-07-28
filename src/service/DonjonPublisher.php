<?php

namespace App\service;

use App\Entity\DonjonGroupe;
use App\Entity\DonjonInstance;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Diffusion temps réel des groupes de donjon via Mercure (patron d'EchangePublisher).
 *
 * Topics :
 *  - `donjon-groupe/{id}` : composition du lobby, pour tous ses inscrits ;
 *  - `user/{id}`          : ce qui doit atteindre un joueur qui ne suit pas (encore) le
 *                           groupe — dissolution, et surtout le lancement, qui le
 *                           téléporte et l'oblige à recharger sa carte.
 *
 * Updates PRIVÉES : il faut un JWT subscriber listant le topic (délivré par
 * /api/mercure/token). Un échec de publication ne doit JAMAIS faire échouer l'action de
 * jeu : la transaction est commitée, le front peut se resynchroniser via /api/donjon/porte.
 */
class DonjonPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly DonjonNormalizer $normalizer,
        private readonly LoggerInterface $logger
    ) {}

    public function publierGroupe(DonjonGroupe $groupe, string $type = 'donjon.groupe.maj'): void
    {
        $payload = [
            'type' => $type,
            'groupe' => $this->normalizer->normalizeGroupe($groupe),
        ];

        $this->publier(sprintf('donjon-groupe/%d', $groupe->getId()), $payload);

        // Une dissolution doit atteindre les inscrits même s'ils ont déjà quitté le topic.
        if ($type === 'donjon.groupe.dissous') {
            foreach ($groupe->getMembres() as $membre) {
                $this->publier(sprintf('user/%d', $membre->getUser()->getId()), $payload);
            }
        }
    }

    /**
     * Le lancement est le seul évènement qui déplace physiquement les autres joueurs :
     * il part sur leur topic personnel pour qu'ils rechargent leur carte sans délai.
     */
    public function publierLancement(DonjonGroupe $groupe, DonjonInstance $instance): void
    {
        $payload = [
            'type' => 'donjon.groupe.lance',
            'groupe' => $this->normalizer->normalizeGroupe($groupe),
            'instance' => $this->normalizer->normalizeInstance($instance),
        ];

        $this->publier(sprintf('donjon-groupe/%d', $groupe->getId()), $payload);
        foreach ($groupe->getMembres() as $membre) {
            $this->publier(sprintf('user/%d', $membre->getUser()->getId()), $payload);
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
            $this->logger->error('Publication Mercure de donjon échouée sur {topic} : {message}', [
                'topic' => $topic,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
