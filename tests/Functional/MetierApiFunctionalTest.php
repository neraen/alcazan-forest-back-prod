<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Métiers : apprentissage chez un maître, plafonds par famille, oubli.
 *
 * Le test crée SES métiers et SON maître, puis nettoie : ce sont des données de contenu,
 * un autre test ne doit pas en hériter (même règle que l'InteractionMaker).
 */
class MetierApiFunctionalTest extends WebTestCase
{
    private const CARTE = 1;

    private array $metiersCrees = [];
    private array $pnjsCrees = [];
    private array $casesPosees = [];

    protected function tearDown(): void
    {
        foreach ($this->casesPosees as $carteCarreauId) {
            $this->sql('UPDATE carte_carreau SET pnj_id = NULL WHERE id = ?', [$carteCarreauId]);
        }
        foreach ($this->pnjsCrees as $pnjId) {
            $this->sql('DELETE FROM pnj_metier WHERE pnj_id = ?', [$pnjId]);
            $this->sql('DELETE FROM sequence WHERE pnj_id = ?', [$pnjId]);
            $this->sql('DELETE FROM pnj WHERE id = ?', [$pnjId]);
        }
        foreach ($this->metiersCrees as $metierId) {
            $this->sql('DELETE FROM joueur_metier WHERE metier_id = ?', [$metierId]);
            $this->sql('DELETE FROM metier WHERE id = ?', [$metierId]);
        }
        parent::tearDown();
    }

    public function testApprendreUnMetierChezUnMaitre(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $mineur = $this->creerMetier('Mineur', 'recolte');

        $reponse = $this->jsonRequest($client, '/api/metier/apprendre', ['metierId' => $mineur], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $reponse['metier']['niveau']);
        $this->assertSame(1, $reponse['placesRestantes']['recolte'], 'Une des deux places de récolte est prise');
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM joueur_metier WHERE user_id = ? AND metier_id = ?',
                [$user['id'], $mineur])
        );
    }

    public function testApprendreDeuxFoisLeMemeMetierEstRefuse(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueur();
        $mineur = $this->creerMetier('Mineur', 'recolte');

        $this->jsonRequest($client, '/api/metier/apprendre', ['metierId' => $mineur], $token);
        $reponse = $this->jsonRequest($client, '/api/metier/apprendre', ['metierId' => $mineur], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('déjà', $reponse['error']);
    }

    /** Le plafond de la famille : le troisième métier de récolte est refusé. */
    public function testLeTroisiemeMetierDeRecolteEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        foreach (['Mineur', 'Herboriste'] as $nom) {
            $this->jsonRequest($client, '/api/metier/apprendre',
                ['metierId' => $this->creerMetier($nom, 'recolte')], $token);
        }

        $reponse = $this->jsonRequest($client, '/api/metier/apprendre',
            ['metierId' => $this->creerMetier('Bûcheron', 'recolte')], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('2 métiers de récolte', $reponse['error']);
        $this->assertSame(
            2,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM joueur_metier WHERE user_id = ?', [$user['id']]),
            'Le refus ne doit rien avoir écrit'
        );
    }

    /** Les familles sont indépendantes : deux récoltes n'empêchent pas trois fabrications. */
    public function testLesFamillesSeComptentSeparement(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueur();
        foreach (['Mineur', 'Herboriste'] as $nom) {
            $this->jsonRequest($client, '/api/metier/apprendre',
                ['metierId' => $this->creerMetier($nom, 'recolte')], $token);
        }

        $reponse = $this->jsonRequest($client, '/api/metier/apprendre',
            ['metierId' => $this->creerMetier('Forgeron', 'craft')], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $reponse['placesRestantes']['recolte']);
        $this->assertSame(2, $reponse['placesRestantes']['craft']);
    }

    public function testOublierUnMetierLibereUnePlaceEtPerdLaProgression(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $mineur = $this->creerMetier('Mineur', 'recolte');
        $this->jsonRequest($client, '/api/metier/apprendre', ['metierId' => $mineur], $token);
        $this->sql('UPDATE joueur_metier SET niveau = 12, experience = 4000 WHERE user_id = ? AND metier_id = ?',
            [$user['id'], $mineur]);

        $reponse = $this->jsonRequest($client, '/api/metier/oublier', ['metierId' => $mineur], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(2, $reponse['placesRestantes']['recolte']);
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM joueur_metier WHERE user_id = ? AND metier_id = ?',
                [$user['id'], $mineur]),
            'La progression est perdue, pas mise de côté'
        );
    }

    public function testOublierUnMetierNonApprisEstRefuse(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueur();

        $reponse = $this->jsonRequest($client, '/api/metier/oublier',
            ['metierId' => $this->creerMetier('Mineur', 'recolte')], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("n'exercez pas", $reponse['error']);
    }

    /** La vue d'un maître dit ce qu'il enseigne ET pourquoi un métier est indisponible. */
    public function testLaVueDuMaitreDeMetierListeCeQuIlEnseigne(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueur();
        $mineur = $this->creerMetier('Mineur', 'recolte');
        $forgeron = $this->creerMetier('Forgeron', 'craft');
        $pnjId = $this->creerMaitre([$mineur, $forgeron]);
        $this->jsonRequest($client, '/api/metier/apprendre', ['metierId' => $mineur], $token);

        $reponse = $this->jsonRequest($client, '/api/pnj/interaction', ['pnjId' => $pnjId], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('metier', $reponse['view']);
        $this->assertCount(2, $reponse['metier']['metiers']);

        $parId = array_column($reponse['metier']['metiers'], null, 'id');
        $this->assertTrue($parId[$mineur]['appris']);
        $this->assertFalse($parId[$mineur]['peutApprendre'], 'Un métier déjà appris ne se réapprend pas');
        $this->assertStringContainsString('déjà', $parId[$mineur]['raison']);
        $this->assertTrue($parId[$forgeron]['peutApprendre']);
        $this->assertNull($parId[$forgeron]['raison']);
    }

    public function testUnMetierInexistantEstRefuse(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueur();

        $reponse = $this->jsonRequest($client, '/api/metier/apprendre', ['metierId' => 999999], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("n'existe pas", $reponse['error']);
    }

    public function testLesMetiersExigentUneAuthentification(): void
    {
        $client = static::createClient();

        $this->jsonRequest($client, '/api/metier/progression', []);

        $this->assertResponseStatusCodeSame(401);
    }

    /* ------------------------------------------------------------------ */

    private function creerMetier(string $nom, string $famille): int
    {
        $this->sql('INSERT INTO metier (nom, description, icone, famille, niveau_max) VALUES (?, NULL, NULL, ?, 200)',
            [$nom . uniqid(), $famille]);
        $id = (int)$this->sqlFetchOne('SELECT MAX(id) FROM metier');
        $this->metiersCrees[] = $id;

        return $id;
    }

    /** @param int[] $metierIds */
    private function creerMaitre(array $metierIds): int
    {
        $this->sql("INSERT INTO pnj (name, avatar, skin, description, type)
            VALUES (?, 'avatar.png', 'skin.png', 'Maître de test', 'metier')", ['Maitre' . uniqid()]);
        $pnjId = (int)$this->sqlFetchOne('SELECT MAX(id) FROM pnj');
        $this->pnjsCrees[] = $pnjId;

        $this->sql("INSERT INTO sequence (pnj_id, position, quete_id, name, dialogue_titre, dialogue_contenu)
            VALUES (?, 1, NULL, 'Accueil', 'Maître', 'Choisis ta voie.')", [$pnjId]);

        foreach ($metierIds as $metierId) {
            $this->sql('INSERT INTO pnj_metier (pnj_id, metier_id) VALUES (?, ?)', [$pnjId, $metierId]);
        }

        // Le PNJ doit être posé sur une case : l'interaction vérifie la proximité.
        $carteCarreauId = (int)$this->sqlFetchOne(
            'SELECT id FROM carte_carreau WHERE carte_id = ? AND abscisse = ? AND ordonnee = ?',
            [self::CARTE, 8, 8]
        );
        $this->sql('UPDATE carte_carreau SET pnj_id = ? WHERE id = ?', [$pnjId, $carteCarreauId]);
        $this->casesPosees[] = $carteCarreauId;

        return $pnjId;
    }

    /** @return array{0: array, 1: string} */
    private function joueur(): array
    {
        $client = static::getClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$user['id']]);
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = 9, case_ordonnee = 9 WHERE id = ?',
            [self::CARTE, $user['id']]);

        return [$user, $token];
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

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('metier', true);
        $user = [
            'pseudo' => 'Met' . substr(md5($unique), 0, 10),
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
        $value = static::getContainer()->get('doctrine')->getConnection()->fetchOne($statement, $params);

        return $value === false ? null : $value;
    }
}
