<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels de l'API contre la base chusei_test (schéma : migrations,
 * contenu : seeds/content-seed.sql — voir DOCUMENTATION.md §10).
 * Chaque test crée ses utilisateurs avec un email unique : pas de nettoyage requis.
 */
class ApiFunctionalTest extends WebTestCase
{
    /** @return mixed décodage JSON brut : certains endpoints legacy renvoient une string */
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
        $unique = uniqid('test', true);
        $user = [
            'pseudo' => 'Func' . substr(md5($unique), 0, 10),
            'email' => $unique . '@test.alcazan.fr',
            'password' => 'password123',
            'sexe' => 'masculin',
        ];

        $response = $this->jsonRequest($client, '/api/users', $user);
        $this->assertResponseStatusCodeSame(201, 'L\'inscription doit répondre 201');
        $user['id'] = $response['id'];

        return $user;
    }

    private function login(KernelBrowser $client, array $user): string
    {
        $response = $this->jsonRequest($client, '/api/login_check', [
            'username' => $user['email'],
            'password' => $user['password'],
        ]);
        $this->assertArrayHasKey('token', $response, 'Le login doit renvoyer un token JWT');

        return $response['token'];
    }

    public function testRegistrationRejectsInvalidPayloadWithViolations(): void
    {
        $client = static::createClient();
        $response = $this->jsonRequest($client, '/api/users', [
            'pseudo' => '',
            'email' => 'pas-un-email',
            'password' => '123',
            'sexe' => 'autre',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('violations', $response);
        $fields = array_column($response['violations'], 'propertyPath');
        $this->assertContains('pseudo', $fields);
        $this->assertContains('email', $fields);
        $this->assertContains('password', $fields);
        $this->assertContains('sexe', $fields);
    }

    public function testRegistrationRejectsDuplicateEmail(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);

        $response = $this->jsonRequest($client, '/api/users', [
            'pseudo' => $user['pseudo'] . 'bis',
            'email' => $user['email'],
            'password' => 'password123',
            'sexe' => 'feminin',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('email', $response['violations'][0]['propertyPath']);
    }

    public function testRegistrationCreatesAFullyPlayableCharacter(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $data = $this->jsonRequest($client, '/api/joueur/data/minimal', [], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame($user['pseudo'], $data['pseudo']);
        $this->assertSame(400, $data['currentLife']);
        $this->assertSame(600, $data['actionPoint']);
        $this->assertSame(800, $data['mouvementPoint']);
        $this->assertSame(10, $data['money']);
        $this->assertSame(1, $data['niveau'], 'PostRegisterSubscriber doit créer le niveau 1');
    }

    public function testApiRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->jsonRequest($client, '/api/joueur/data/minimal', []);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminRoutesAreForbiddenToRegularPlayers(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/editor/list', [], $token);
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/insert/lvl');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCaracteristiquesUpdateEnforcesServerSideCap(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Niveau 1 → plafond = 1*5+6 = 11 points. 100 points de force = triche.
        $this->jsonRequest($client, '/api/joueur/caracteristiques/update', ['force' => 100], $token);
        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * L'annuaire est vide sans alignement, et il le DIT.
     *
     * Remplace l'ancien `/api/guildes/player`, supprimé avec la refonte des guildes : le
     * message explique désormais pourquoi la liste est vide, au lieu de renvoyer un tableau
     * vide qu'on pouvait confondre avec « aucune guilde n'existe ».
     */
    public function testAnnuaireEstVideSansAlignement(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $data = $this->jsonRequest($client, '/api/guilde/annuaire', [], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $data['guildes']);
        $this->assertNotNull($data['message'], "Le joueur doit savoir qu'il lui manque un alignement.");
    }

    public function testPlayerCanMoveOnTheMap(): void
    {
        $client = static::createClient();

        // Libère la case cible : le joueur d'un run précédent peut encore l'occuper
        // (les utilisateurs de test s'accumulent dans chusei_test).
        static::getContainer()->get('doctrine')->getConnection()->executeStatement(
            'UPDATE carte_carreau SET joueur_id = NULL WHERE carte_id = 2 AND abscisse = 10 AND ordonnee = 9'
        );

        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Spawn en (9,9) carte 2 → déplacement d'une case vers (10,9) : 1 PM consommé.
        $data = $this->jsonRequest($client, '/api/joueur/case/update_position', [
            'mapId' => 2,
            'caseAbscisse' => 10,
            'caseOrdonnee' => 9,
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(10, $data['abscisseJoueur']);
        $this->assertSame(9, $data['ordonneeJoueur']);
        $this->assertSame(799, $data['pm']);
        $this->assertNotEmpty($data['cases']);
    }

    /**
     * Le serveur fait foi sur la légalité d'un pas : avant, n'importe quelle case libre
     * envoyée par un client modifié était appliquée (téléportation, décor traversé, PM
     * négatifs). Chaque refus est un 400 `{"message"}`, et ne touche ni à la position ni
     * aux PM.
     *
     * @dataProvider pasIllegaux
     */
    public function testUnPasIllegalEstRefuse(array $depart, int $pm, array $destination, string $motif): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $connexion = static::getContainer()->get('doctrine')->getConnection();
        $connexion->executeStatement(
            'UPDATE user SET map_id = 2, case_abscisse = ?, case_ordonnee = ?, mouvement_point = ? WHERE id = ?',
            [$depart[0], $depart[1], $pm, $user['id']]
        );

        $data = $this->jsonRequest($client, '/api/joueur/case/update_position', [
            'mapId' => 2,
            'caseAbscisse' => $destination[0],
            'caseOrdonnee' => $destination[1],
        ], $token);

        $this->assertResponseStatusCodeSame(400, $motif);
        $this->assertStringContainsString($motif, $data['message']);

        $apres = $connexion->fetchAssociative(
            'SELECT case_abscisse, case_ordonnee, mouvement_point FROM user WHERE id = ?',
            [$user['id']]
        );
        $this->assertSame([$depart[0], $depart[1], $pm], [
            (int)$apres['case_abscisse'], (int)$apres['case_ordonnee'], (int)$apres['mouvement_point'],
        ], 'Un pas refusé ne déplace pas et ne coûte rien');
    }

    public static function pasIllegaux(): iterable
    {
        // Carte 2 (tutoriel) : (11,10) est un décor infranchissable, le PNJ 1 est en (6,11).
        yield 'téléportation' => [[9, 9], 800, [12, 9], 'trop loin'];
        yield 'décor infranchissable' => [[10, 9], 800, [11, 10], 'obstacle'];
        yield 'case d\'un PNJ' => [[7, 11], 800, [6, 11], 'Quelqu\'un'];
        yield 'plus de PM' => [[9, 9], 0, [10, 9], 'points de mouvement'];
        yield 'case hors carte' => [[0, 0], 800, [-1, 0], 'n\'existe pas'];
        yield 'sur place' => [[9, 9], 800, [9, 9], 'déjà sur cette case'];
    }

    /**
     * Sans `mapId`, la carte renvoyée est celle où se trouve RÉELLEMENT le joueur. C'est ce
     * qui empêche un client à l'état périmé de dessiner le personnage aux coordonnées d'une
     * autre carte (le « téléporté au milieu d'un étang » de l'app mobile).
     */
    public function testLaCarteSansIdEstCelleOuSeTrouveLeJoueur(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $data = $this->jsonRequest($client, '/api/map/cases/data', [], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(2, (int)$data['mapId'], 'Un nouveau joueur est sur la carte de départ');
        $this->assertSame(9, $data['abscisseJoueur']);
        $this->assertSame(9, $data['ordonneeJoueur']);
        $this->assertNotEmpty($data['cases']);

        // La consultation explicite d'une autre carte reste possible (MapMaker).
        $autre = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => 1], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, (int)$autre['mapId']);
    }
}
