<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * InteractionMaker : /api/interaction/editor est réservé ROLE_ADMIN, la sauvegarde est
 * transactionnelle et conserve les ids des conditions.
 */
class InteractionEditorApiFunctionalTest extends WebTestCase
{
    public function testLEditeurEstReserveAuxAdmins(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $this->jsonRequest($client, '/api/interaction/editor/list', [], $token);

        $this->assertResponseStatusCodeSame(403, "Même les lectures sont réservées aux admins");
    }

    /** L'exécution reste une route JOUEUR : le préfixe editor ne doit pas l'avoir capturée. */
    public function testLExecutionResteAccessibleAuxJoueurs(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $this->jsonRequest($client, '/api/interaction/executer', ['carteCarreauId' => 1], $token);

        $this->assertResponseStatusCodeSame(400, "Refus métier attendu, pas un 403");
    }

    public function testLaConfigDecritTypesPorteesEtConditions(): void
    {
        $client = static::createClient();
        $config = $this->jsonRequest($client, '/api/interaction/editor/config', [], $this->loginAdmin($client));

        $this->assertSame(
            ['recolter', 'ouvrir', 'actionner', 'effet'],
            array_column($config['types'], 'value')
        );
        $this->assertSame(
            ['joueur', 'monde', 'instance'],
            array_column($config['portees'], 'value'),
            'Les trois portées de recharge doivent être proposées'
        );
        foreach (['niveau', 'classe', 'quete_terminee', 'possede_objet', 'alignement'] as $condition) {
            $this->assertArrayHasKey($condition, $config['conditions']);
        }
    }

    public function testCreerPuisRelireUneInteractionComplete(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $metierId = $this->creerMetier();

        $cree = $this->jsonRequest($client, '/api/interaction/editor/save', [
            'nom' => 'Herbe de test',
            'type' => 'recolter',
            'coutPa' => 4,
            'metierId' => $metierId,
            'niveauMetierMin' => 2,
            'experienceMetier' => 15,
            'cooldownSecondes' => 600,
            'porteeRecharge' => 'monde',
            'recompense' => ['objetId' => 1, 'quantity' => 3, 'experience' => 25],
            'conditions' => [['type' => 'niveau', 'params' => ['niveau' => 10]]],
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('monde', $cree['interaction']['porteeRecharge']);
        $this->assertSame(2, $cree['interaction']['niveauMetierMin']);
        $this->assertSame(3, $cree['recompense']['quantity']);
        $this->assertCount(1, $cree['conditions']);
        $this->assertSame(10, $cree['conditions'][0]['params']['niveau']);

        $relu = $this->jsonRequest($client, '/api/interaction/editor/get',
            ['interactionId' => $cree['interaction']['id']], $token);
        $this->assertSame($cree['interaction'], $relu['interaction']);
    }

    public function testUneSauvegardeIdentiqueNeChangeAucunIdDeCondition(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $cree = $this->jsonRequest($client, '/api/interaction/editor/save', [
            'nom' => 'Coffre de test',
            'type' => 'ouvrir',
            'recompense' => ['money' => 100],
            'conditions' => [
                ['type' => 'niveau', 'params' => ['niveau' => 5]],
                ['type' => 'possede_objet', 'params' => ['objetId' => 1, 'quantite' => 2]],
            ],
        ], $token);

        $payload = $cree['interaction'] + ['recompense' => $cree['recompense'], 'conditions' => $cree['conditions']];
        $apres = $this->jsonRequest($client, '/api/interaction/editor/save', $payload, $token);

        $this->assertSame(
            array_column($cree['conditions'], 'id'),
            array_column($apres['conditions'], 'id'),
            'Les ids de condition doivent survivre à une sauvegarde'
        );
    }

    public function testRetirerUneConditionLaSupprime(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $cree = $this->jsonRequest($client, '/api/interaction/editor/save', [
            'nom' => 'Levier de test',
            'type' => 'actionner',
            'conditions' => [
                ['type' => 'niveau', 'params' => ['niveau' => 5]],
                ['type' => 'niveau', 'params' => ['niveau' => 9]],
            ],
        ], $token);

        $payload = $cree['interaction'] + [
            'recompense' => $cree['recompense'],
            'conditions' => [$cree['conditions'][0]],
        ];
        $apres = $this->jsonRequest($client, '/api/interaction/editor/save', $payload, $token);

        $this->assertCount(1, $apres['conditions']);
        $this->assertSame($cree['conditions'][0]['id'], $apres['conditions'][0]['id']);
    }

    public function testUnJsonDEffetInvalideEstRefuse(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);

        $refus = $this->jsonRequest($client, '/api/interaction/editor/save', [
            'nom' => 'Effet cassé',
            'type' => 'effet',
            'effect' => 'recompense_boss',
            'effectParams' => 'ceci nest pas du json',
        ], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('JSON', $refus['error']);
    }

    /** Supprimer une interaction posée laisserait des cases orphelines. */
    public function testUneInteractionPoseeNeSeSupprimePas(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $cree = $this->jsonRequest($client, '/api/interaction/editor/save',
            ['nom' => 'Posée de test', 'type' => 'ouvrir'], $token);
        $interactionId = $cree['interaction']['id'];

        $carteCarreauId = (int)$this->sqlFetchOne(
            'SELECT id FROM carte_carreau WHERE carte_id = 1 AND abscisse = 15 AND ordonnee = 15');
        $this->sql('UPDATE carte_carreau SET interaction_id = ? WHERE id = ?', [$interactionId, $carteCarreauId]);

        $refus = $this->jsonRequest($client, '/api/interaction/editor/delete',
            ['interactionId' => $interactionId], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('posée sur 1 case', $refus['error']);

        $this->sql('UPDATE carte_carreau SET interaction_id = NULL WHERE id = ?', [$carteCarreauId]);
        $this->jsonRequest($client, '/api/interaction/editor/delete', ['interactionId' => $interactionId], $token);
        $this->assertResponseIsSuccessful('Une fois retirée des cartes, elle se supprime');
    }

    /* ------------------------------------------------------------------ */

    private function creerMetier(): int
    {
        $this->sql("INSERT INTO metier (nom, description, icone, famille, niveau_max) VALUES (?, NULL, NULL, 'recolte', 100)",
            ['Metier' . uniqid()]);

        return (int)$this->sqlFetchOne('SELECT MAX(id) FROM metier');
    }

    private function loginAdmin(KernelBrowser $client): string
    {
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);

        return $this->login($client, $user);
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
        $unique = uniqid('editint', true);
        $user = [
            'pseudo' => 'EditInt' . substr(md5($unique), 0, 10),
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
