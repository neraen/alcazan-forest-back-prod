<?php

namespace App\Tests\Functional;

use App\Enum\CategorieEvenement;
use App\service\HistoriqueService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Journal du joueur : `/api/historique/infos`.
 *
 * L'endpoint sert désormais une UNION — les événements typés de `evenement_jeu` et les
 * anciennes lignes libres de `historique`, dans une catégorie « Archives ». Ce que ces tests
 * verrouillent, c'est ce que la refonte d'écran avait explicitement refusé d'inventer faute
 * de typage : des catégories RÉELLES.
 */
class HistoriqueApiFunctionalTest extends WebTestCase
{
    public function testUnAnonymeNAccedePasAuJournal(): void
    {
        $client = static::createClient();

        $this->jsonRequest($client, '/api/historique/infos', []);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    /** Les catégories descendent du serveur : le front n'en connaît aucune en dur. */
    public function testLesCategoriesSontServiesParLeServeur(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $reponse = $this->jsonRequest($client, '/api/historique/infos', [], $token);
        $this->assertResponseIsSuccessful();

        $valeurs = array_column($reponse['categories'], 'valeur');
        foreach (CategorieEvenement::cases() as $categorie) {
            $this->assertContains($categorie->value, $valeurs);
        }
        $this->assertContains(
            HistoriqueService::CATEGORIE_ARCHIVES,
            $valeurs,
            "Les lignes héritées ont leur propre catégorie : c'est un héritage, pas une classification."
        );
    }

    /* ---------------------------------------------------------------- */
    /* L'union                                                          */
    /* ---------------------------------------------------------------- */

    public function testLeJournalMeleEvenementsTypesEtArchives(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, quantite, montant_or, cree_le)
             VALUES ('monstre_tue', ?, 1, 0, NOW())",
            [$user['id']]
        );
        $this->sql(
            "INSERT INTO historique (user_id, message, date, is_external)
             VALUES (?, 'Une vieille ligne libre', DATE_SUB(NOW(), INTERVAL 1 DAY), 0)",
            [$user['id']]
        );

        $rows = $this->jsonRequest($client, '/api/historique/infos', [], $token)['rows'];
        $categories = array_column($rows, 'categorie');

        $this->assertContains('combat', $categories, 'L\'événement typé porte sa vraie catégorie.');
        $this->assertContains(HistoriqueService::CATEGORIE_ARCHIVES, $categories);
    }

    /** Le plus récent d'abord, quelle que soit la source. */
    public function testLeJournalEstTrieDuPlusRecentAuPlusAncien(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql(
            "INSERT INTO historique (user_id, message, date, is_external)
             VALUES (?, 'Ancienne', DATE_SUB(NOW(), INTERVAL 10 DAY), 0)",
            [$user['id']]
        );
        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, quantite, montant_or, cree_le)
             VALUES ('recolte', ?, 3, 0, NOW())",
            [$user['id']]
        );

        $dates = array_column($this->jsonRequest($client, '/api/historique/infos', [], $token)['rows'], 'date');
        $triees = $dates;
        rsort($triees);

        $this->assertSame($triees, $dates);
    }

    /**
     * « Subi » distingue ce que le joueur a fait de ce qu'on lui a fait — et une même
     * ligne `MORT_JOUEUR` se lit dans les deux sens selon qui la consulte.
     */
    public function testUneMemeMortEstActionPourLeTueurEtSubiePourLaVictime(): void
    {
        $client = static::createClient();
        $tueur = $this->registerUser($client);
        $victime = $this->registerUser($client);
        $tokenTueur = $this->login($client, $tueur);
        $tokenVictime = $this->login($client, $victime);

        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, cible_user_id, quantite, montant_or, contexte, cree_le)
             VALUES ('mort_joueur', ?, ?, 1, 0, ?, NOW())",
            [$tueur['id'], $victime['id'], json_encode(['cause' => 'pvp'])]
        );

        $cotéTueur = $this->ligneMort($this->jsonRequest($client, '/api/historique/infos', [], $tokenTueur)['rows']);
        $cotéVictime = $this->ligneMort($this->jsonRequest($client, '/api/historique/infos', [], $tokenVictime)['rows']);

        $this->assertNotNull($cotéTueur);
        $this->assertNotNull($cotéVictime);
        $this->assertFalse($cotéTueur['subi'], 'Le tueur en est l\'auteur.');
        $this->assertTrue($cotéVictime['subi'], 'La victime la subit.');
        $this->assertSame(
            $cotéTueur['phrase'],
            $cotéVictime['phrase'],
            'Une seule ligne, une seule phrase : le journal ne duplique pas la mort.'
        );
    }

    /** Le journal d'un joueur ne montre pas celui des autres. */
    public function testLeJournalNeMontreQueSesPropresEvenements(): void
    {
        $client = static::createClient();
        $moi = $this->registerUser($client);
        $autre = $this->registerUser($client);
        $token = $this->login($client, $moi);

        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, quantite, montant_or, cree_le)
             VALUES ('craft_termine', ?, 1, 0, NOW())",
            [$autre['id']]
        );

        $rows = $this->jsonRequest($client, '/api/historique/infos', [], $token)['rows'];

        $this->assertNotContains('craft_termine', array_column($rows, 'type'));
    }

    /* ---------------------------------------------------------------- */
    /* Les archives                                                     */
    /* ---------------------------------------------------------------- */

    /** Le HTML des anciens messages est retiré : la page rend du texte. */
    public function testLeHtmlDesArchivesEstRetire(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql(
            "INSERT INTO historique (user_id, message, date, is_external)
             VALUES (?, 'Premier<br />Second', NOW(), 0)",
            [$user['id']]
        );

        $phrases = array_column($this->jsonRequest($client, '/api/historique/infos', [], $token)['rows'], 'phrase');
        $trouvee = array_values(array_filter($phrases, static fn ($p) => str_contains($p, 'Premier')))[0] ?? null;

        $this->assertNotNull($trouvee);
        $this->assertStringNotContainsString('<br', $trouvee);
        $this->assertStringContainsString('Second', $trouvee);
    }

    /**
     * Une partie des archives a été écrite doublement encodée (« infligÃ© »). Le défaut est
     * dans les DONNÉES ; il est réparé à l'affichage, et la réparation ne doit jamais
     * abîmer une ligne déjà saine.
     */
    public function testLeDoubleEncodageDesArchivesEstRepareSansAbimerLeReste(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // « infligé » doublement encodé, puis « assoiffé » correctement encodé.
        $this->sql(
            "INSERT INTO historique (user_id, message, date, is_external) VALUES (?, ?, NOW(), 0)",
            [$user['id'], 'Il a inflig' . "\u{00C3}\u{00A9}" . ' des dommages']
        );
        $this->sql(
            "INSERT INTO historique (user_id, message, date, is_external) VALUES (?, ?, NOW(), 0)",
            [$user['id'], 'Le boss assoiffé frappe']
        );

        $phrases = array_column($this->jsonRequest($client, '/api/historique/infos', [], $token)['rows'], 'phrase');

        $this->assertContains('Il a infligé des dommages', $phrases, 'Le double encodage est réparé.');
        $this->assertContains('Le boss assoiffé frappe', $phrases, 'Une ligne saine reste intacte.');
    }

    /* ---------------------------------------------------------------- */

    private function ligneMort(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (($row['type'] ?? null) === 'mort_joueur') {
                return $row;
            }
        }

        return null;
    }

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('hist', true);
        $user = [
            'pseudo' => 'Hst' . substr(md5($unique), 0, 10),
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
