<?php

namespace App\Tests\Functional;

use App\Config\ClassementConfig;
use App\Enum\CategorieClassement;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Classements publics.
 *
 * Les trois propriétés qui ne se voient pas à l'œil nu et que ce fichier verrouille :
 * l'ordre décroissant, l'exclusion des comptes marqués `hors_classement`, et le partage du
 * rang entre ex æquo (que l'index du tableau côté client ne saurait pas exprimer).
 */
class ClassementApiFunctionalTest extends WebTestCase
{
    public function testUnAnonymeNAccedePasAuClassement(): void
    {
        $client = static::createClient();

        $this->jsonRequest($client, '/api/classement/liste', []);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    /** Un classement est PUBLIC : un joueur ordinaire doit y accéder. */
    public function testUnJoueurOrdinaireAccedeAuClassement(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $this->jsonRequest($client, '/api/classement/liste', [], $token);

        $this->assertResponseIsSuccessful();
    }

    public function testUneCategorieInconnueEstRefusee(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $this->jsonRequest($client, '/api/classement/liste', ['categorie' => 'nawak'], $token);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    /** Ouvrir la page sans rien préciser doit marcher et rendre la première catégorie. */
    public function testSansCategorieLaPremiereEstServie(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $reponse = $this->jsonRequest($client, '/api/classement/liste', [], $token);
        $this->assertResponseIsSuccessful();

        $this->assertSame(CategorieClassement::cases()[0]->value, $reponse['categorie']);
        $this->assertCount(count(CategorieClassement::cases()), $reponse['categories']);
        $this->assertArrayHasKey('intitule', $reponse['categories'][0], "Le front ne connaît aucun libellé en dur.");
    }

    /* ---------------------------------------------------------------- */
    /* Ordre, exclusion, ex æquo                                         */
    /* ---------------------------------------------------------------- */

    public function testLeClassementEstDecroissantEtBorne(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $reponse = $this->jsonRequest($client, '/api/classement/liste', ['categorie' => 'richesse'], $token);
        $valeurs = array_column($reponse['classement'], 'valeur');

        $triees = $valeurs;
        rsort($triees);
        $this->assertSame($triees, $valeurs, 'Le classement doit être décroissant.');
        $this->assertLessThanOrEqual(ClassementConfig::TAILLE_TOP, count($valeurs));
        $this->assertSame(ClassementConfig::TAILLE_TOP, $reponse['taille']);
    }

    public function testUnCompteHorsClassementNApparaitPas(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $exclu = $this->registerUser($client);
        // Une fortune qui le placerait premier s'il n'était pas exclu.
        $this->sql('UPDATE user SET money = 99999999, hors_classement = 1 WHERE id = ?', [$exclu['id']]);

        $reponse = $this->jsonRequest($client, '/api/classement/liste', ['categorie' => 'richesse'], $token);

        $this->assertNotContains(
            $exclu['pseudo'],
            array_column($reponse['classement'], 'pseudo'),
            "Sans ce filtre, le compte de développement trusterait tous les podiums."
        );
    }

    /**
     * Deux joueurs à égalité partagent le rang, et le suivant saute d'autant. C'est ce que
     * le serveur calcule et que `index + 1` côté client ne saurait pas exprimer.
     */
    public function testLesExAequoPartagentLeRang(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $reponse = $this->jsonRequest($client, '/api/classement/liste', ['categorie' => 'richesse'], $token);
        $lignes = $reponse['classement'];

        $rangsParValeur = [];
        foreach ($lignes as $ligne) {
            $rangsParValeur[$ligne['valeur']][] = $ligne['rang'];
        }

        foreach ($rangsParValeur as $valeur => $rangs) {
            $this->assertCount(
                1,
                array_unique($rangs),
                "Toutes les entrées à $valeur doivent partager le même rang."
            );
        }

        // Et le rang reste cohérent avec la position : le n-ième rang distinct ne peut pas
        // être inférieur au nombre d'entrées qui le précèdent.
        foreach ($lignes as $index => $ligne) {
            $this->assertLessThanOrEqual($index + 1, $ligne['rang']);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Rang personnel                                                    */
    /* ---------------------------------------------------------------- */

    /**
     * Le rang servi par `/moi` doit être EXACTEMENT celui que la liste affiche pour ce joueur.
     *
     * L'assertion porte sur la concordance des deux endpoints, et non sur « il doit être
     * premier » : la base de test n'est pas réinitialisée entre les exécutions, et un test qui
     * fabrique un joueur très riche en laisse un derrière lui. Deux exécutions plus tard, deux
     * millionnaires ex æquo font échouer une assertion de position — sans que rien ne soit
     * cassé. La concordance, elle, reste vraie quoi qu'il y ait déjà en base.
     */
    public function testMonRangEstCoherentAvecLaPositionDansLeTop(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Assez élevé pour figurer dans le top, et borné par la capacité d'un INT MySQL.
        $fortune = 2000000000 - (int) $user['id'];
        $this->sql('UPDATE user SET money = ? WHERE id = ?', [$fortune, $user['id']]);

        $liste = $this->jsonRequest($client, '/api/classement/liste', ['categorie' => 'richesse'], $token);
        $moi = $this->jsonRequest($client, '/api/classement/moi', [], $token);

        $rangs = array_column($moi['rangs'], null, 'categorie');
        $this->assertFalse($moi['horsClassement']);
        $this->assertSame($fortune, $rangs['richesse']['valeur']);

        $dansLaListe = null;
        foreach ($liste['classement'] as $ligne) {
            if ($ligne['userId'] === $user['id']) {
                $dansLaListe = $ligne;
                break;
            }
        }

        $this->assertNotNull($dansLaListe, 'Une telle fortune doit figurer dans le top.');
        $this->assertSame(
            $dansLaListe['rang'],
            $rangs['richesse']['rang'],
            "Le rang personnel et celui de la liste doivent être le MÊME nombre."
        );
    }

    public function testUnCompteExcluNAPasDeRang(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);
        $this->sql('UPDATE user SET hors_classement = 1 WHERE id = ?', [$user['id']]);

        $moi = $this->jsonRequest($client, '/api/classement/moi', [], $token);

        $this->assertTrue($moi['horsClassement']);
        foreach ($moi['rangs'] as $rang) {
            $this->assertNull(
                $rang['rang'],
                "Afficher un rang à un joueur absent de toutes les listes serait mentir."
            );
        }
    }

    /** Toutes les catégories déclarées doivent réellement répondre. */
    public function testChaqueCategorieDeclareeRepond(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        foreach (CategorieClassement::cases() as $categorie) {
            $reponse = $this->jsonRequest($client, '/api/classement/liste', ['categorie' => $categorie->value], $token);
            $this->assertResponseIsSuccessful("La catégorie {$categorie->value} doit répondre.");
            $this->assertSame($categorie->value, $reponse['categorie']);
            $this->assertIsArray($reponse['classement']);
        }
    }

    /* ---------------------------------------------------------------- */

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('clst', true);
        $user = [
            'pseudo' => 'Clst' . substr(md5($unique), 0, 10),
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
