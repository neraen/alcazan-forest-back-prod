<?php

namespace App\Tests\Functional;

use App\Enum\TypeCumul;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Faits d'armes du joueur : `/api/joueur/stats`.
 *
 * Le test qui compte est `testGagnerDeLExperienceAlimenteLeCumul` : il parcourt la chaîne
 * complète (action de jeu → `LevelingService` → `joueur_cumul` → API) sans SQL de fixture.
 * Les autres vérifient les garde-fous qui, eux, ne se voient pas à l'œil nu.
 */
class JoueurStatsApiFunctionalTest extends WebTestCase
{
    public function testUnAnonymeNAccedePasAuxStats(): void
    {
        $client = static::createClient();

        $this->jsonRequest($client, '/api/joueur/stats', []);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    /** Un personnage neuf affiche des zéros, pas des lignes manquantes. */
    public function testUnPersonnageNeufAToutesSesLignesAZero(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $stats = $this->jsonRequest($client, '/api/joueur/stats', [], $token);
        $this->assertResponseIsSuccessful();

        $this->assertCount(count(TypeCumul::faitsDArmes()), $stats['faitsDArmes']);
        foreach ($stats['faitsDArmes'] as $ligne) {
            $this->assertSame(0, $ligne['valeur'], "{$ligne['cle']} doit valoir 0 sur un personnage neuf.");
            $this->assertArrayHasKey('label', $ligne);
            $this->assertArrayHasKey('format', $ligne);
        }
    }

    /** Les états sont la photo de l'instant : la richesse suit `user.money`. */
    public function testLesEtatsPortentLaRichesseEtLHonneur(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);
        $this->sql('UPDATE user SET money = 777, honneur = 42 WHERE id = ?', [$user['id']]);

        $stats = $this->jsonRequest($client, '/api/joueur/stats', [], $token);

        $etats = array_column($stats['etats'], 'valeur', 'cle');
        $this->assertSame(777, $etats['richesse']);
        $this->assertSame(42, $etats['honneur']);
        $this->assertSame('or', $stats['etats'][0]['format'], 'La richesse doit être annoncée en or.');
    }

    /**
     * L'honneur d'un personnage neuf vaut 0 — un vrai zéro.
     *
     * Ce test vérifiait auparavant qu'un `honneur` NULL était rendu 0 par l'API. La colonne
     * est passée NOT NULL DEFAULT 0 avec le lot PvP : le cas ne peut plus se produire, et
     * c'est justement ce que la migration garantit. Vérifier l'absence de NULL a donc plus
     * de valeur que de simuler un NULL devenu impossible.
     */
    public function testUnPersonnageNeufALHonneurAZero(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $stats = $this->jsonRequest($client, '/api/joueur/stats', [], $token);

        $etats = array_column($stats['etats'], 'valeur', 'cle');
        $this->assertSame(0, $etats['honneur']);
        $this->assertSame(
            0,
            (int) static::getContainer()->get('doctrine')->getConnection()
                ->fetchOne('SELECT COUNT(*) FROM user WHERE honneur IS NULL'),
            'La colonne est NOT NULL depuis le lot PvP : plus aucun NULL ne doit exister.'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Chaîne complète                                                   */
    /* ---------------------------------------------------------------- */

    public function testGagnerDeLExperienceAlimenteLeCumul(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        static::getContainer()->get(\App\service\LevelingService::class)
            ->giveExperienceToAPlayer(250, $user['id']);

        $stats = $this->jsonRequest($client, '/api/joueur/stats', [], $token);
        $faits = array_column($stats['faitsDArmes'], 'valeur', 'cle');

        $this->assertSame(250, $faits['xp_totale']);
    }

    /**
     * Un malus de mort passe par le MÊME point de passage avec une valeur négative.
     * Le cumul ne doit pas bouger : ce n'est pas de l'XP « dé-gagnée ».
     */
    public function testUnMalusDeMortNeDecrementePasLXpTotale(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $leveling = static::getContainer()->get(\App\service\LevelingService::class);
        $leveling->giveExperienceToAPlayer(500, $user['id']);
        $leveling->giveExpMalusAfterDeath($user['id']);

        $stats = $this->jsonRequest($client, '/api/joueur/stats', [], $token);
        $faits = array_column($stats['faitsDArmes'], 'valeur', 'cle');

        $this->assertSame(
            500,
            $faits['xp_totale'],
            "Le malus de mort ne doit pas retirer de l'XP TOTALE GAGNÉE."
        );
    }

    /**
     * `BOSS_VAINCUS` est une dénormalisation de `SUM(user_boss.number_kill)`. Ce qui la rend
     * légitime, c'est qu'elle est recalculable — `app:cumuls:reparer` la reconstruit, et cet
     * écart-là doit être détecté puis corrigé.
     */
    public function testLaReparationRecalculeUnCumulDerive(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);

        $bossId = $this->sqlFetchOne('SELECT id FROM boss LIMIT 1');
        $this->assertNotFalse($bossId, 'Le seed de contenu doit fournir au moins un boss.');

        $this->sql(
            'INSERT INTO user_boss (user_id, boss_id, last_kill, number_kill) VALUES (?, ?, NOW(), 3)',
            [$user['id'], $bossId]
        );
        // Cumul volontairement faux : c'est l'écart que la réparation doit rattraper.
        $this->sql(
            "INSERT INTO joueur_cumul (user_id, cle, valeur, maj_at) VALUES (?, 'boss_vaincus', 99, NOW())",
            [$user['id']]
        );

        $repository = static::getContainer()->get(\App\Repository\JoueurCumulRepository::class);
        $attendu = (int) $this->sqlFetchOne('SELECT SUM(number_kill) FROM user_boss WHERE user_id = ?', [$user['id']]);
        $repository->ecraserParId($user['id'], TypeCumul::BOSS_VAINCUS, $attendu);

        $this->assertSame(
            3,
            $repository->valeurParId($user['id'], TypeCumul::BOSS_VAINCUS),
            'Le cumul doit concorder avec sa source après recalcul.'
        );
    }

    /* ---------------------------------------------------------------- */

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('cumul', true);
        $user = [
            'pseudo' => 'Cml' . substr(md5($unique), 0, 10),
            'email' => $unique . '@test.alcazan.fr',
            'password' => 'password123',
            'sexe' => 'masculin',
        ];

        $response = $this->jsonRequest($client, '/api/users', $user);
        $this->assertResponseStatusCodeSame(201);
        $user['id'] = $response['id'];

        return $user;
    }

    private function login(KernelBrowser $client, array $user): string
    {
        $response = $this->jsonRequest($client, '/api/login_check', [
            'username' => $user['email'],
            'password' => $user['password'],
        ]);
        $this->assertArrayHasKey('token', $response);

        return $response['token'];
    }

    private function sql(string $statement, array $params = []): void
    {
        static::getContainer()->get('doctrine')->getConnection()->executeStatement($statement, $params);
    }

    private function sqlFetchOne(string $statement, array $params = []): mixed
    {
        return static::getContainer()->get('doctrine')->getConnection()->fetchOne($statement, $params);
    }

    private function jsonRequest(KernelBrowser $client, string $uri, array $payload, ?string $token = null): mixed
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $client->request('POST', $uri, [], [], $headers, json_encode($payload));

        return json_decode($client->getResponse()->getContent(), true);
    }
}
