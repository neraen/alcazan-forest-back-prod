<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conditions de passage entre salles, sur le contenu seedé (donjon 1).
 *
 * Le test CONFIGURE lui-même les conditions (population, nettoyage, leviers) puis les
 * remet à zéro : elles font partie du contenu, un autre test ne doit pas en hériter.
 */
class DonjonSalleApiFunctionalTest extends WebTestCase
{
    private const CASE_PORTE = 1979;
    private const CARTE_1 = 8;
    private const CARTE_2 = 9;
    private const CARTE_3 = 10;
    private const WRAP_1_VERS_2 = 2702;
    private const WRAP_2_VERS_3 = 3180;

    protected function tearDown(): void
    {
        // Le contenu est partagé : on rend les salles telles qu'on les a trouvées.
        $this->sql("UPDATE donjon_salle SET `condition` = 'aucune', condition_params = '{}',
                    monstre_id = NULL, nombre_monstres = 0 WHERE donjon_id = 1");
        parent::tearDown();
    }

    public function testUneSalleSePeupleALArriveeEtUneSeuleFois(): void
    {
        $client = static::createClient();
        $this->sql('UPDATE donjon_salle SET monstre_id = 1, nombre_monstres = 3 WHERE donjon_id = 1 AND ordre = 2');

        [$user, $token] = $this->joueurDansLeDonjon($client);
        $instanceId = $this->instanceDe($user['id']);

        $reponse = $this->allerEnSalle2($client, $token);
        $this->assertStringContainsString('barrent la route', (string)$reponse['annonce']);
        $this->assertSame(3, $this->monstresVivants($instanceId, self::CARTE_2));

        // Aller-retour : la salle ne doit PAS se repeupler (sinon ferme à XP).
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = 11, case_ordonnee = 14 WHERE id = ?',
            [self::CARTE_2, $user['id']]);
        $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => 3444, 'targetMapId' => self::CARTE_1, 'targetWrap' => self::WRAP_1_VERS_2,
        ], $token);
        $retour = $this->allerEnSalle2($client, $token);

        $this->assertNull($retour['annonce'], 'Une salle déjà peuplée ne se repeuple pas');
        $this->assertSame(3, $this->monstresVivants($instanceId, self::CARTE_2));
    }

    /**
     * La population d'une salle est un monstre ORDINAIRE : elle descend avec la CARTE
     * (`renfortId` sur la case, ce qui permet le ciblage automatique quand on marche
     * dessus), elle se lit comme un monstre du monde ouvert, et elle rend expérience et
     * butin. Le bug d'origine : trois monstres visibles en sprite, impossibles à cibler
     * (type de cible inconnu de la carte de cible) et impossibles à tuer.
     */
    public function testLaPopulationEstUnMonstreOrdinaire(): void
    {
        $client = static::createClient();
        $this->sql('UPDATE donjon_salle SET monstre_id = 1, nombre_monstres = 3 WHERE donjon_id = 1 AND ordre = 2');

        [$user, $token] = $this->joueurDansLeDonjon($client);
        $this->allerEnSalle2($client, $token);

        $carte = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => self::CARTE_2], $token);
        $peuplees = array_values(array_filter($carte['cases'], fn (array $case) => !empty($case['renfortId'])));

        $this->assertCount(3, $peuplees, 'Chaque monstre de la salle doit être porté par sa case');
        foreach ($carte['cases'] as $case) {
            $this->assertNull($case['hasMonstre'], 'Rien ne doit être écrit dans le décor partagé');
        }

        // La carte de cible est mutualisée avec celle des monstres du monde ouvert.
        $cible = $this->jsonRequest($client, '/api/target/renfort', ['targetId' => $peuplees[0]['renfortId']], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Psilofrost', $cible['nomMonstre']);
        $this->assertSame(1, $cible['quantiteMonstre']);
        $this->assertArrayHasKey('monstreLifeMax', $cible);

        // On se place SUR la case du monstre (c'est ainsi qu'on le cible) et on l'achève.
        $this->sql('UPDATE user SET case_abscisse = ?, case_ordonnee = ?, current_life = max_life, action_point = 100 WHERE id = ?',
            [$peuplees[0]['abscisse'], $peuplees[0]['ordonnee'], $user['id']]);
        $this->sql('UPDATE donjon_instance_monstre SET current_life = 1 WHERE id = ?', [$peuplees[0]['renfortId']]);

        $coup = $this->jsonRequest($client, '/api/donjon/renfort/attaquer', [
            'renfortId' => $peuplees[0]['renfortId'],
            'spellId' => 3,
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($coup['mort']);
        $this->assertNotNull($coup['killMessage'], 'Le front décible sur killMessage');
        $this->assertGreaterThan(0, $coup['experience'], 'Un monstre de donjon rend de l\'expérience');
        $this->assertIsArray($coup['droppedItems']);
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT valeur FROM joueur_compteur WHERE user_id = ? AND type = ?',
                [$user['id'], 'monstre_tue']),
            'La mise à mort compte comme celle de n\'importe quel monstre'
        );
        $this->assertSame(2, $this->monstresVivants($this->instanceDe($user['id']), self::CARTE_2));
    }

    /**
     * Consulter les cases d'une salle de donjon où l'on ne se trouve PAS (c'est ce que
     * fait le MapMaker, qui charge n'importe quelle carte par cet endpoint) doit rendre
     * les cases de CETTE carte, et ne doit déplacer personne.
     *
     * Le bug : l'éjection « salle de donjon sans instance » s'appliquait à toute lecture.
     * Ouvrir une salle dans le MapMaker téléportait donc l'admin à la sortie du donjon et
     * renvoyait les cases de la carte de SORTIE — d'où un fond de salle recouvert des
     * collisions d'une autre carte, et des cases éditées dans la mauvaise carte.
     */
    public function testConsulterUneSalleDeDonjonNeTeleporteNiNeRenvoieUneAutreCarte(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Le joueur est dans le monde ouvert, sans instance : il ne doit pas bouger.
        $this->sql('UPDATE user SET map_id = 6, case_abscisse = 10, case_ordonnee = 2 WHERE id = ?', [$user['id']]);

        $vue = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => self::CARTE_3], $token);

        $this->assertSame(self::CARTE_3, (int)$vue['mapId']);
        $this->assertSame(
            [self::CARTE_3],
            array_values(array_unique(array_column($vue['cases'], 'carteId'))),
            'Les cases doivent toutes appartenir à la carte demandée'
        );
        $this->assertSame(
            6,
            (int)$this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$user['id']]),
            'Une simple consultation ne déplace pas le joueur'
        );
    }

    public function testLaConditionDeNettoyageBloquePuisLibereLePassage(): void
    {
        $client = static::createClient();
        $this->sql('UPDATE donjon_salle SET monstre_id = 1, nombre_monstres = 3 WHERE donjon_id = 1 AND ordre = 2');
        $this->sql("UPDATE donjon_salle SET `condition` = 'salle_nettoyee' WHERE donjon_id = 1 AND ordre = 3");

        [$user, $token] = $this->joueurDansLeDonjon($client);
        $instanceId = $this->instanceDe($user['id']);
        $this->allerEnSalle2($client, $token);

        $refus = $this->allerEnSalle3($client, $token, $user['id']);

        $this->assertArrayHasKey('message', $refus);
        $this->assertStringContainsString('Il en reste 3', $refus['message']);
        $this->assertSame(
            self::CARTE_2,
            (int)$this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$user['id']]),
            'Un passage refusé ne doit pas déplacer le joueur'
        );

        // Une fois la salle nettoyée, le passage s'ouvre.
        $this->sql('UPDATE donjon_instance_monstre SET vivant = 0, current_life = 0 WHERE instance_id = ?', [$instanceId]);
        $succes = $this->allerEnSalle3($client, $token, $user['id']);

        $this->assertSame(self::CARTE_3, (int)$succes['mapId']);
    }

    /** Une porte franchie le reste : sinon un retour en arrière enfermerait le joueur. */
    public function testUnePorteFranchieResteOuverte(): void
    {
        $client = static::createClient();
        $this->sql('UPDATE donjon_salle SET monstre_id = 1, nombre_monstres = 2 WHERE donjon_id = 1 AND ordre = 2');
        $this->sql("UPDATE donjon_salle SET `condition` = 'salle_nettoyee' WHERE donjon_id = 1 AND ordre = 3");

        [$user, $token] = $this->joueurDansLeDonjon($client);
        $instanceId = $this->instanceDe($user['id']);
        $this->allerEnSalle2($client, $token);
        $this->sql('UPDATE donjon_instance_monstre SET vivant = 0 WHERE instance_id = ?', [$instanceId]);
        $this->allerEnSalle3($client, $token, $user['id']);

        // On fait « revivre » les monstres : la condition n'est plus remplie, mais la
        // porte a déjà été franchie.
        $this->sql('UPDATE donjon_instance_monstre SET vivant = 1 WHERE instance_id = ?', [$instanceId]);
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = 11, case_ordonnee = 14 WHERE id = ?',
            [self::CARTE_2, $user['id']]);

        $retour = $this->allerEnSalle3($client, $token, $user['id']);

        $this->assertSame(self::CARTE_3, (int)$retour['mapId'], 'La porte reste ouverte pour toute l\'expédition');
    }

    public function testLaSalleDEntreeNePeutPasExigerDAvoirNettoye(): void
    {
        $client = static::createClient();
        $token = $this->loginAdmin($client);
        $donnees = $this->jsonRequest($client, '/api/donjon/editor/get', ['donjonId' => 1], $token);
        $payload = $donnees['donjon'] + ['salles' => $donnees['salles'], 'mecaniques' => $donnees['mecaniques']];
        $payload['salles'][0]['condition'] = 'salle_nettoyee';

        $refus = $this->jsonRequest($client, '/api/donjon/editor/save', $payload, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("salle d'entrée", $refus['error']);
    }

    /* ------------------------------------------------------------------ */

    private function monstresVivants(int $instanceId, int $carteId): int
    {
        return (int)$this->sqlFetchOne(
            'SELECT COUNT(*) FROM donjon_instance_monstre WHERE instance_id = ? AND carte_id = ? AND vivant = 1',
            [$instanceId, $carteId]
        );
    }

    private function allerEnSalle2(KernelBrowser $client, string $token): array
    {
        return $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => self::WRAP_1_VERS_2, 'targetMapId' => self::CARTE_2, 'targetWrap' => 3444,
        ], $token);
    }

    private function allerEnSalle3(KernelBrowser $client, string $token, int $userId): array
    {
        $this->sql('UPDATE user SET map_id = ? WHERE id = ?', [self::CARTE_2, $userId]);

        return $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => self::WRAP_2_VERS_3, 'targetMapId' => self::CARTE_3, 'targetWrap' => 3828,
        ], $token);
    }

    private function instanceDe(int $userId): int
    {
        return (int)$this->sqlFetchOne(
            'SELECT instance_id FROM donjon_instance_membre WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /** @return array{0: array, 1: string} */
    private function joueurDansLeDonjon(KernelBrowser $client): array
    {
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE niveau_joueur SET niveau_id = 20 WHERE user_id = ?', [$user['id']]);
        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$user['id']]);
        $this->sql('UPDATE user SET map_id = 6, case_abscisse = 10, case_ordonnee = 2 WHERE id = ?', [$user['id']]);
        $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => self::CASE_PORTE, 'targetMapId' => self::CARTE_1, 'targetWrap' => 3062,
        ], $token);

        return [$user, $token];
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
        $unique = uniqid('salle', true);
        $user = [
            'pseudo' => 'Salle' . substr(md5($unique), 0, 10),
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
