<?php

namespace App\Tests\Functional;

use App\Config\JournalConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Journal d'administration : `/api/admin/stats/*` est réservé ROLE_ADMIN par `security.yaml`,
 * et le flux se filtre par joueur, par catégorie et par période.
 *
 * Un test vaut plus que les autres : `testUneConnexionProduitUnEvenement` parcourt la chaîne
 * complète sans SQL de fixture — s'inscrire, se connecter, puis lire le journal par l'API.
 * C'est lui qui prouve que le socle est réellement branché, et pas seulement que les requêtes
 * du repository sont correctes.
 */
class AdminStatsApiFunctionalTest extends WebTestCase
{
    /* ---------------------------------------------------------------- */
    /* Sécurité                                                          */
    /* ---------------------------------------------------------------- */

    public function testUnJoueurOrdinaireNAccedePasAuJournal(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/admin/stats/journal', [], $token);

        $this->assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            "Le préfixe ^/api/admin/ doit rester réservé à ROLE_ADMIN."
        );
    }

    public function testUnAnonymeNAccedePasAuJournal(): void
    {
        $client = static::createClient();

        $this->jsonRequest($client, '/api/admin/stats/journal', []);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    /* ---------------------------------------------------------------- */
    /* Chaîne complète                                                   */
    /* ---------------------------------------------------------------- */

    public function testUneConnexionProduitUnEvenement(): void
    {
        $client = static::createClient();
        $observe = $this->registerUser($client);
        $this->login($client, $observe);

        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);

        $reponse = $this->jsonRequest($client, '/api/admin/stats/journal', [
            'userId' => $observe['id'],
        ], $tokenAdmin);
        $this->assertResponseIsSuccessful();

        $types = array_column($reponse['evenements'], 'type');
        $this->assertContains(
            'connexion',
            $types,
            "Se connecter doit laisser une trace : c'est la seule source de « joueurs actifs »."
        );

        $connexion = $reponse['evenements'][array_search('connexion', $types, true)];
        $this->assertSame($observe['id'], $connexion['acteur']['id']);
        $this->assertSame($observe['pseudo'], $connexion['acteur']['pseudo']);
        $this->assertSame('systeme', $connexion['categorie']);
        $this->assertStringContainsString(
            $observe['pseudo'],
            $connexion['phrase'],
            'La phrase est rendue côté serveur, pseudo résolu compris.'
        );
    }

    /** Une seule ligne par JOUR, pas une par jeton demandé. */
    public function testDeuxConnexionsLeMemeJourNeFontQuUnEvenement(): void
    {
        $client = static::createClient();
        $observe = $this->registerUser($client);
        $this->login($client, $observe);
        $this->login($client, $observe);
        $this->login($client, $observe);

        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);

        $reponse = $this->jsonRequest($client, '/api/admin/stats/journal', [
            'userId' => $observe['id'],
            'types' => ['connexion'],
        ], $tokenAdmin);
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            1,
            $reponse['total'],
            "Trois jetons dans la même journée ne font qu'une journée jouée."
        );
    }

    /* ---------------------------------------------------------------- */
    /* Filtres                                                           */
    /* ---------------------------------------------------------------- */

    public function testLeFiltreParJoueurRamenneCeQuIlAFaitEtSubi(): void
    {
        $client = static::createClient();
        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);
        $victime = $this->registerUser($client);
        $tueur = $this->registerUser($client);

        // Le tueur agit, la victime subit : UNE ligne, lisible dans les deux sens.
        $this->insererEvenement('mort_joueur', $tueur['id'], $victime['id']);

        foreach ([$tueur['id'], $victime['id']] as $userId) {
            $reponse = $this->jsonRequest($client, '/api/admin/stats/journal', [
                'userId' => $userId,
                'types' => ['mort_joueur'],
            ], $tokenAdmin);

            $this->assertSame(
                1,
                $reponse['total'],
                "La même ligne doit répondre à « ce qu'il a fait » ET à « ce qu'il a subi »."
            );
        }
    }

    public function testLeFiltreParCategorieElargitAuxTypesDuRayon(): void
    {
        $client = static::createClient();
        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);
        $joueur = $this->registerUser($client);

        $this->insererEvenement('monstre_tue', $joueur['id']);
        $this->insererEvenement('hdv_achat', $joueur['id']);

        $combat = $this->jsonRequest($client, '/api/admin/stats/journal', [
            'userId' => $joueur['id'],
            'categorie' => 'combat',
        ], $tokenAdmin);

        $types = array_unique(array_column($combat['evenements'], 'type'));
        $this->assertContains('monstre_tue', $types);
        $this->assertNotContains('hdv_achat', $types, 'Un achat n\'est pas du combat.');
    }

    public function testUneCategorieInconnueNeFiltrePas(): void
    {
        $client = static::createClient();
        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);

        $this->jsonRequest($client, '/api/admin/stats/journal', ['categorie' => 'nawak'], $tokenAdmin);

        $this->assertResponseIsSuccessful(
            "Un filtre périmé côté écran ne doit pas transformer une consultation en erreur."
        );
    }

    public function testLaPaginationBorneLaPage(): void
    {
        $client = static::createClient();
        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);
        $joueur = $this->registerUser($client);

        for ($i = 0; $i < 5; ++$i) {
            $this->insererEvenement('recolte', $joueur['id']);
        }

        $page = $this->jsonRequest($client, '/api/admin/stats/journal', [
            'userId' => $joueur['id'],
            'types' => ['recolte'],
            'page' => 1,
            'parPage' => 2,
        ], $tokenAdmin);

        $this->assertCount(2, $page['evenements']);
        $this->assertSame(5, $page['total'], 'Le total ignore la pagination.');

        $demesuree = $this->jsonRequest($client, '/api/admin/stats/journal', [
            'parPage' => 99999,
        ], $tokenAdmin);
        $this->assertSame(
            JournalConfig::PAGE_MAX,
            $demesuree['parPage'],
            'La taille de page est plafonnée côté serveur.'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Référentiels                                                      */
    /* ---------------------------------------------------------------- */

    public function testLesReferentielsDecriventTypesEtCategories(): void
    {
        $client = static::createClient();
        $admin = $this->registerAdmin($client);
        $tokenAdmin = $this->login($client, $admin);

        $reponse = $this->jsonRequest($client, '/api/admin/stats/referentiels', [], $tokenAdmin);
        $this->assertResponseIsSuccessful();

        $this->assertNotEmpty($reponse['types']);
        $this->assertNotEmpty($reponse['categories']);
        $this->assertNotEmpty($reponse['joueurs']);

        $premier = $reponse['types'][0];
        $this->assertArrayHasKey('valeur', $premier);
        $this->assertArrayHasKey('label', $premier);
        $this->assertArrayHasKey('categorie', $premier, "Le front doit pouvoir grouper sans rien savoir en dur.");
    }

    /* ---------------------------------------------------------------- */
    /* Purge                                                             */
    /* ---------------------------------------------------------------- */

    public function testLaPurgeSupprimeLAncienEtGardeLeRecent(): void
    {
        $client = static::createClient();
        $joueur = $this->registerUser($client);

        $this->insererEvenement('recolte', $joueur['id']);
        $this->sql(
            "UPDATE evenement_jeu SET cree_le = ? WHERE acteur_id = ?",
            [(new \DateTimeImmutable(sprintf('-%d days', JournalConfig::RETENTION_JOURS + 5)))->format('Y-m-d H:i:s'), $joueur['id']]
        );
        $this->insererEvenement('monstre_tue', $joueur['id']);

        static::getContainer()->get(\App\Repository\EvenementJeuRepository::class)->supprimerAvant(
            new \DateTimeImmutable(sprintf('-%d days', JournalConfig::RETENTION_JOURS)),
            JournalConfig::LOT_PURGE
        );

        $restants = $this->sqlFetchAll(
            "SELECT type FROM evenement_jeu WHERE acteur_id = ?",
            [$joueur['id']]
        );
        $types = array_column($restants, 'type');

        $this->assertNotContains('recolte', $types, "L'événement au-delà de la rétention doit partir.");
        $this->assertContains('monstre_tue', $types, "L'événement récent doit rester.");
    }

    /* ---------------------------------------------------------------- */
    /* Aides                                                             */
    /* ---------------------------------------------------------------- */

    private function insererEvenement(string $type, int $acteurId, ?int $cibleUserId = null): void
    {
        $this->sql(
            'INSERT INTO evenement_jeu (type, acteur_id, cible_user_id, quantite, montant_or, cree_le)
             VALUES (?, ?, ?, 1, 0, NOW())',
            [$type, $acteurId, $cibleUserId]
        );
    }

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('stats', true);
        $user = [
            'pseudo' => 'Stat' . substr(md5($unique), 0, 10),
            'email' => $unique . '@test.alcazan.fr',
            'password' => 'password123',
            'sexe' => 'masculin',
        ];

        $response = $this->jsonRequest($client, '/api/users', $user);
        $this->assertResponseStatusCodeSame(201);
        $user['id'] = $response['id'];

        return $user;
    }

    private function registerAdmin(KernelBrowser $client): array
    {
        $user = $this->registerUser($client);
        $this->sql('UPDATE user SET roles = ? WHERE id = ?', [json_encode(['ROLE_ADMIN']), $user['id']]);

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

    private function sqlFetchAll(string $statement, array $params = []): array
    {
        return static::getContainer()->get('doctrine')->getConnection()->fetchAllAssociative($statement, $params);
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
