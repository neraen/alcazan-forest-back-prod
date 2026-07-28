<?php

namespace App\service;

/**
 * Fabrique les JWT d'ABONNEMENT Mercure (HS256, signés avec la même clé que le hub).
 *
 * EventSource ne peut pas envoyer de header Authorization : le front passe ce token en
 * query param `authorization` de l'URL du hub. Chaque token liste EXPLICITEMENT les topics
 * autorisés du joueur (`user/{id}`, `echange/{id}` de sa session active) — jamais de
 * wildcard : un joueur ne doit pas pouvoir écouter les échanges des autres.
 */
class MercureJwtFactory
{
    public function __construct(
        private readonly string $mercureJwtSecret,
        private readonly string $mercurePublicUrl
    ) {}

    /**
     * @param string[] $topics topics autorisés à l'abonnement
     *
     * @return array{token: string, mercureUrl: string, topics: string[], expiresAt: int}
     */
    public function creerTokenAbonnement(array $topics, int $dureeSecondes = 3600): array
    {
        $expiration = time() + $dureeSecondes;
        $token = $this->signer([
            'mercure' => ['subscribe' => array_values($topics)],
            'exp' => $expiration,
        ]);

        return [
            'token' => $token,
            'mercureUrl' => $this->mercurePublicUrl,
            'topics' => array_values($topics),
            'expiresAt' => $expiration,
        ];
    }

    private function signer(array $payload): string
    {
        $entete = $this->base64Url(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $corps = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', $entete . '.' . $corps, $this->mercureJwtSecret, true));

        return $entete . '.' . $corps . '.' . $signature;
    }

    private function base64Url(string $donnees): string
    {
        return rtrim(strtr(base64_encode($donnees), '+/', '-_'), '=');
    }
}
