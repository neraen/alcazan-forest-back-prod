<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * DonjonMaker : /api/donjon/editor est réservé ROLE_ADMIN, la sauvegarde est
 * transactionnelle et conserve les ids.
 *
 * Les ids stables ne sont pas un détail : `donjon_instance.mecaniques_jouees` référence
 * des ids de mécanique, et `donjon_verrou`/`donjon_instance` des ids de donjon. Une
 * sauvegarde qui recrée tout casserait les expéditions en cours.
 */
class DonjonEditorApiFunctionalTest extends WebTestCase
{
    private const DONJON_ID = 1;

    public function testLEditeurEstReserveAuxAdmins(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $this->jsonRequest($client, '/api/donjon/editor/list', [], $token);

        $this->assertResponseStatusCodeSame(403, "Même les lectures du DonjonMaker sont réservées aux admins");
    }

    public function testUnAdminLitLeDonjonAvecSonPlanEtSesMecaniques(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);

        $donnees = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('Donjon Scintillant', $donnees['donjon']['nom']);
        $this->assertCount(5, $donnees['salles']);
        $this->assertSame('entree', $donnees['salles'][0]['type']);
        $this->assertNotEmpty($donnees['mecaniques']);
    }

    /** La config pilote le formulaire : chaque mécanique de l'enum doit y figurer. */
    public function testLaConfigDecritToutesLesMecaniques(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);

        $config = $this->jsonRequest($client, '/api/donjon/editor/config', [], $token);

        foreach (['zone_telegraphiee', 'adds', 'enrage', 'enigme_leviers'] as $type) {
            $this->assertArrayHasKey($type, $config);
            $this->assertNotEmpty($config[$type]['champs'], "La mécanique {$type} doit décrire ses champs");
            $this->assertNotEmpty($config[$type]['aide'], "La mécanique {$type} doit expliquer son effet en jeu");
        }
    }

    public function testUneSauvegardeIdentiqueNeChangeAucunId(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);

        $avant = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);
        $apres = $this->jsonRequest($client, '/api/donjon/editor/save', $this->enPayload($avant), $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            array_column($avant['salles'], 'id'),
            array_column($apres['salles'], 'id'),
            'Les ids de salle doivent survivre à une sauvegarde'
        );
        $this->assertSame(
            array_column($avant['mecaniques'], 'id'),
            array_column($apres['mecaniques'], 'id'),
            'Les ids de mécanique sont référencés par les instances en cours'
        );
    }

    /**
     * `condition` est un MOT RÉSERVÉ MySQL. Une sauvegarde qui ne CHANGE pas la condition
     * ne prouve rien : Doctrine n'écrit que les champs devenus sales, la colonne fautive
     * n'apparaît donc dans aucun UPDATE. Ce test la rend sale exprès — c'est le seul
     * chemin qui déclenchait l'erreur de syntaxe 1064 et rendait le DonjonMaker
     * incapable d'enregistrer quoi que ce soit.
     */
    public function testChangerLaConditionDUneSalleSEnregistre(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);

        $donnees = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);
        $payload = $this->enPayload($donnees);
        $payload['salles'][2]['condition'] = 'leviers';
        $payload['salles'][2]['conditionParams'] = ['leviers' => 3, 'fenetreSecondes' => 20];

        $apres = $this->jsonRequest($client, '/api/donjon/editor/save', $payload, $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('leviers', $apres['salles'][2]['condition']);
        $this->assertSame(3, $apres['salles'][2]['conditionParams']['leviers']);

        // On remet le plan d'origine : le contenu est partagé par les autres tests.
        $this->jsonRequest($client, '/api/donjon/editor/save', $this->enPayload($donnees), $token);
        $rendu = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);
        $this->assertSame($donnees['salles'][2]['condition'], $rendu['salles'][2]['condition']);
    }

    public function testLOrdreDesSallesSuitLaListeEnvoyee(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);

        $donnees = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);
        $payload = $this->enPayload($donnees);
        // On intervertit les deux premières salles.
        [$payload['salles'][0], $payload['salles'][1]] = [$payload['salles'][1], $payload['salles'][0]];
        $premiereCarte = $payload['salles'][0]['carteId'];

        $apres = $this->jsonRequest($client, '/api/donjon/editor/save', $payload, $token);

        $this->assertSame(1, $apres['salles'][0]['ordre']);
        $this->assertSame($premiereCarte, $apres['salles'][0]['carteId'], "L'ordre de la liste fait foi");

        // On remet le plan d'origine pour ne pas polluer les autres tests.
        $this->jsonRequest($client, '/api/donjon/editor/save', $this->enPayload($donnees), $token);
    }

    public function testUnDonjonSansSalleEstRefuse(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $payload = $this->enPayload($this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token));
        $payload['salles'] = [];

        $reponse = $this->jsonRequest($client, '/api/donjon/editor/save', $payload, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('au moins une salle', $reponse['error']);
    }

    public function testUneCarteEnDoubleDansLePlanEstRefuseeSansRienModifier(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $donnees = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);
        $payload = $this->enPayload($donnees);
        $payload['salles'][1]['carteId'] = $payload['salles'][0]['carteId'];
        $payload['salles'][1]['id'] = null;

        $reponse = $this->jsonRequest($client, '/api/donjon/editor/save', $payload, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('deux fois', $reponse['error']);

        // La transaction doit avoir tout annulé.
        $apres = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token);
        $this->assertSame(
            array_column($donnees['salles'], 'id'),
            array_column($apres['salles'], 'id'),
            'Un refus ne doit rien laisser derrière lui'
        );
    }

    public function testUneFenetreDeVieInverseeEstRefusee(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $payload = $this->enPayload($this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => self::DONJON_ID], $token));
        $payload['mecaniques'][0]['vieMax'] = 10;
        $payload['mecaniques'][0]['vieMin'] = 90;

        $reponse = $this->jsonRequest($client, '/api/donjon/editor/save', $payload, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('borne basse', $reponse['error']);
    }

    /** Supprimer un donjon avec un historique effacerait des parties : on refuse. */
    public function testUnDonjonAvecDesExpeditionsNeSeSupprimePas(): void
    {
        $client = static::createClient();
        $joueur = $this->registerUser($client);
        $tokenJoueur = $this->login($client, $joueur);
        $this->sql('UPDATE niveau_joueur SET niveau_id = 20 WHERE user_id = ?', [$joueur['id']]);
        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$joueur['id']]);
        $this->sql('UPDATE user SET map_id = 6, case_abscisse = 10, case_ordonnee = 2 WHERE id = ?', [$joueur['id']]);
        $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => 1979, 'targetMapId' => 8, 'targetWrap' => 3062,
        ], $tokenJoueur);

        $token = $this->loginAdmin($client);
        $reponse = $this->jsonRequest($client, '/api/donjon/editor/delete', ['donjonId' => self::DONJON_ID], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Désactivez', $reponse['error']);
        $this->assertNotNull(
            $this->sqlFetchOne('SELECT id FROM donjon WHERE id = ?', [self::DONJON_ID]),
            'Le donjon doit toujours exister'
        );
    }

    /* ------------------------------------------------------------------ */

    /** La réponse de get() renvoyée telle quelle en payload de save(). */
    private function enPayload(array $donnees): array
    {
        return $donnees['donjon'] + [
            'salles' => $donnees['salles'],
            'mecaniques' => $donnees['mecaniques'],
        ];
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
        $unique = uniqid('editeur', true);
        $user = [
            'pseudo' => 'Editeur' . substr(md5($unique), 0, 10),
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
