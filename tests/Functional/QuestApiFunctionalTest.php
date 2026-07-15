<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours de quête de bout en bout sur chusei_test (schéma : migrations,
 * contenu : seeds/content-seed.sql). S'appuie sur le contenu seedé :
 * PNJ 1 = Leopold (quête 1 « Choix de la classe », 2 séquences),
 * PNJ 4 = Aubergiste (séquence 3 sans quête, effet entrer_auberge),
 * action 7 = récompense de boss posée sur la case (11,4) de la carte 15.
 */
class QuestApiFunctionalTest extends WebTestCase
{
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
        $unique = uniqid('quest', true);
        $user = [
            'pseudo' => 'Quest' . substr(md5($unique), 0, 10),
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

    public function testLInteractionPnjEstUneLecturePure(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $interaction = $this->jsonRequest($client, '/api/pnj/interaction', ['pnjId' => 1], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('quest', $interaction['view']);
        $this->assertSame('available', $interaction['quest']['status']);
        $this->assertSame('Choix de la classe', $interaction['quest']['name']);

        // Régression majeure : l'ancien POST /api/pnj créait le user_quete à la consultation.
        $count = $this->sqlFetchOne('SELECT COUNT(*) FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(0, (int)$count, "Consulter un PNJ ne doit PAS démarrer la quête");
    }

    public function testParcoursCompletDeLaQueteInitiale(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        /* Démarrage explicite */
        $step = $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame('step', $step['status']);
        $this->assertCount(4, $step['step']['actions'], 'La séquence 1 propose les 4 classes');
        $this->assertNotEmpty($step['step']['dialogue']['paragraphs']);

        /* Choix de la classe (effet scripté) */
        $archer = array_values(array_filter($step['step']['actions'], fn ($a) => $a['label'] === 'Devenir Archer'))[0];
        $this->assertSame('SCRIPTED_EFFECT', $archer['type']);

        $next = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => $step['step']['sequenceId'],
            'actionId' => $archer['actionId'],
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('step', $next['status'], 'Le choix de classe mène au dialogue de fin');
        $this->assertTrue($next['needRefresh']);
        $rewardTypes = array_column($next['feedback']['rewards'], 'type');
        $this->assertContains('or', $rewardTypes);
        $this->assertContains('experience', $rewardTypes);
        $this->assertContains('equipement', $rewardTypes);

        $classeId = $this->sqlFetchOne('SELECT classe_id FROM user WHERE id = ?', [$user['id']]);
        $classeNom = $this->sqlFetchOne('SELECT nom FROM classe WHERE id = ?', [$classeId]);
        $this->assertSame('archer', $classeNom);

        /* Dernière séquence : « Terminer » clôt la quête */
        $this->assertCount(1, $next['step']['actions']);
        $done = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => $next['step']['sequenceId'],
            'actionId' => $next['step']['actions'][0]['actionId'],
        ], $token);

        $this->assertSame('done', $done['status']);
        $isDone = $this->sqlFetchOne('SELECT is_done FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(1, (int)$isDone);

        /* L'interaction reflète l'état terminé */
        $interaction = $this->jsonRequest($client, '/api/pnj/interaction', ['pnjId' => 1], $token);
        $this->assertSame('done', $interaction['quest']['status']);
    }

    public function testDemarrerDeuxFoisNeCreePasDeDoublon(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);
        $second = $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('step', $second['status'], 'Re-démarrer renvoie l\'étape courante');
        $count = $this->sqlFetchOne('SELECT COUNT(*) FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(1, (int)$count);
    }

    public function testUneActionSurUneAutreEtapeQueLaCouranteEchoue(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);

        // Le joueur est à l'étape 1 (séquence 1) : jouer « Terminer » (séquence 2) doit échouer.
        $terminerActionId = $this->sqlFetchOne(
            "SELECT sa.action_id FROM sequence_action sa JOIN sequence s ON s.id = sa.sequence_id WHERE s.quete_id = 1 AND s.position = 2"
        );
        $response = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => 2,
            'actionId' => (int)$terminerActionId,
        ], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $response);
    }

    public function testLeDialogueAubergeExigeLaProximiteDuPnj(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Le joueur spawn carte 2 : l'aubergiste (PNJ 4) est carte 4 en (14,5).
        $response = $this->jsonRequest($client, '/api/quest/action', ['sequenceId' => 3, 'actionId' => 5], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('trop loin', $response['error']);
    }

    public function testLAubergisteTeleporteLeJoueurAdjacent(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Téléporte le joueur à côté de l'aubergiste (carte 4, PNJ en (14,5)).
        $this->sql('UPDATE user SET map_id = 4, case_abscisse = 13, case_ordonnee = 5 WHERE id = ?', [$user['id']]);

        $response = $this->jsonRequest($client, '/api/quest/action', ['sequenceId' => 3, 'actionId' => 5], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('done', $response['status']);
        $this->assertTrue($response['needRefresh']);
        $this->assertStringContainsString('auberge', $response['feedback']['messages'][0]['text']);

        $mapId = $this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$user['id']]);
        $this->assertSame(14, (int)$mapId, 'Le joueur doit être téléporté dans l\'auberge (carte 14)');
    }

    public function testLActionDeCaseExigeLaProximite(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // L'action 7 est posée carte 15 en (11,4) ; le joueur spawn carte 2.
        $response = $this->jsonRequest($client, '/api/map/action', ['actionId' => 7], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('trop loin', $response['error']);
    }

    public function testLActionDeCaseRecompenseBoss(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE user SET map_id = 15, case_abscisse = 11, case_ordonnee = 5 WHERE id = ?', [$user['id']]);

        $response = $this->jsonRequest($client, '/api/map/action', ['actionId' => 7], $token);

        $this->assertResponseIsSuccessful();
        $this->assertNotEmpty($response['messages']);
        $this->assertStringContainsString('Vous gagnez', $response['messages'][0]);
    }

    public function testLeQuestMakerEstReserveAuxAdmins(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/editor/list', [], $token);
        $this->assertResponseStatusCodeSame(403, 'Même les lectures du QuestMaker sont réservées aux admins');
    }

    public function testLaSauvegardeEditeurEstIdempotenteEtSansChurnDIds(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        $quest = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $this->assertResponseIsSuccessful();
        $originalSequenceIds = array_column($quest['sequences'], 'id');
        $originalActionIds = array_merge(...array_map(fn ($s) => array_column($s['actions'], 'id'), $quest['sequences']));

        /* Re-sauvegarde du payload tel quel : aucun id ne doit changer
           (l'ancien updateQuest supprimait et recréait toutes les actions). */
        $saved = $this->jsonRequest($client, '/api/quest/editor/save', $quest, $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame($originalSequenceIds, array_column($saved['sequences'], 'id'));
        $this->assertSame(
            $originalActionIds,
            array_merge(...array_map(fn ($s) => array_column($s['actions'], 'id'), $saved['sequences']))
        );

        /* Modification d'un libellé : toujours les mêmes ids */
        $saved['sequences'][0]['actions'][0]['label'] = 'Devenir Archer (test)';
        $saved2 = $this->jsonRequest($client, '/api/quest/editor/save', $saved, $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Devenir Archer (test)', $saved2['sequences'][0]['actions'][0]['label']);
        $this->assertSame($originalActionIds, array_merge(...array_map(fn ($s) => array_column($s['actions'], 'id'), $saved2['sequences'])));

        /* Remise en état pour les autres tests */
        $saved2['sequences'][0]['actions'][0]['label'] = 'Devenir Archer';
        $this->jsonRequest($client, '/api/quest/editor/save', $saved2, $token);
        $this->assertResponseIsSuccessful();
    }

    public function testLaSauvegardeEditeurRefuseUneSequenceSansAction(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        $quest = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $quest['sequences'][0]['actions'] = [];

        $response = $this->jsonRequest($client, '/api/quest/editor/save', $quest, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('au moins une action', $response['error']);
    }
}
