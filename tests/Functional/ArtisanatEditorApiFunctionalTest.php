<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ArtisanatMaker : accès admin, ids stables, refus de suppression tant que c'est référencé.
 */
class ArtisanatEditorApiFunctionalTest extends WebTestCase
{
    private array $metiersCrees = [];
    private array $recettesCreees = [];
    private array $objetsCrees = [];

    protected function tearDown(): void
    {
        foreach ($this->recettesCreees as $recetteId) {
            $this->sql('DELETE FROM craft_commande WHERE recette_id = ?', [$recetteId]);
            $this->sql('DELETE FROM recette_ingredient WHERE recette_id = ?', [$recetteId]);
            $this->sql('DELETE FROM recette WHERE id = ?', [$recetteId]);
        }
        foreach ($this->objetsCrees as $objetId) {
            $this->sql('DELETE FROM recette_ingredient WHERE objet_id = ?', [$objetId]);
            $this->sql('DELETE FROM recompense WHERE objet_id = ?', [$objetId]);
            $this->sql('DELETE FROM objet WHERE id = ?', [$objetId]);
        }
        foreach ($this->metiersCrees as $metierId) {
            $this->sql('DELETE FROM joueur_metier WHERE metier_id = ?', [$metierId]);
            $this->sql('DELETE FROM pnj_metier WHERE metier_id = ?', [$metierId]);
            $this->sql('UPDATE objet SET metier_id = NULL WHERE metier_id = ?', [$metierId]);
            $this->sql('DELETE FROM metier WHERE id = ?', [$metierId]);
        }
        parent::tearDown();
    }

    /** L'éditeur est fermé aux non-admins, LECTURES COMPRISES. */
    public function testLEditeurEstFermeAuxNonAdmins(): void
    {
        $client = static::createClient();
        $token = $this->joueurSimple($client);

        $this->jsonRequest($client, '/api/artisanat/editor/list', [], $token);
        $this->assertResponseStatusCodeSame(403);

        $this->jsonRequest($client, '/api/artisanat/editor/config', [], $token);
        $this->assertResponseStatusCodeSame(403);
    }

    /** …alors que l'atelier reste une route joueur. */
    public function testLAtelierResteOuvertAuxJoueurs(): void
    {
        $client = static::createClient();
        $token = $this->joueurSimple($client);

        $this->jsonRequest($client, '/api/craft/atelier', [], $token);

        $this->assertResponseIsSuccessful();
    }

    public function testLaConfigDecritFamillesEtModes(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);

        $config = $this->jsonRequest($client, '/api/artisanat/editor/config', [], $token);

        $this->assertSame(['recolte', 'craft'], array_column($config['familles'], 'value'));
        $this->assertSame(2, $config['plafonds']['recolte']);
        $this->assertSame(3, $config['plafonds']['craft']);
        $this->assertNotEmpty($config['modesCraft']);
    }

    public function testCreerPuisRelireUnMetier(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);

        $sauvegarde = $this->jsonRequest($client, '/api/artisanat/editor/metier/save', [
            'nom' => 'Verrier' . uniqid(),
            'description' => 'Souffle le verre.',
            'famille' => 'craft',
            'niveauMax' => 150,
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->metiersCrees[] = $sauvegarde['id'];

        $relu = $this->jsonRequest($client, '/api/artisanat/editor/metier/get', ['id' => $sauvegarde['id']], $token);

        $this->assertSame('craft', $relu['famille']);
        $this->assertSame(150, $relu['niveauMax']);
    }

    public function testUneFamilleInconnueEstRefusee(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);

        $reponse = $this->jsonRequest($client, '/api/artisanat/editor/metier/save', [
            'nom' => 'Bidon', 'famille' => 'sorcellerie',
        ], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Famille', $reponse['error']);
    }

    /** Le cœur du patron d'édition : les ids d'ingrédients survivent à une sauvegarde. */
    public function testLesIdsDIngredientsSontStables(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);
        $metierId = $this->creerMetier('Verrier', 'craft');
        $sable = $this->creerObjet('Sable');
        $cendre = $this->creerObjet('Cendre');

        $premiere = $this->sauvegarderRecette($client, $token, $metierId, [
            ['objetId' => $sable, 'quantite' => 4],
            ['objetId' => $cendre, 'quantite' => 2],
        ]);
        $idsAvant = array_column($premiere['ingredients'], 'id');

        // On renvoie les mêmes lignes, avec une quantité modifiée sur la première.
        $seconde = $this->jsonRequest($client, '/api/artisanat/editor/recette/save', [
            'recette' => $premiere['recette'],
            'produit' => $premiere['produit'],
            'ingredients' => [
                ['id' => $idsAvant[0], 'objetId' => $sable, 'quantite' => 9],
                ['id' => $idsAvant[1], 'objetId' => $cendre, 'quantite' => 2],
            ],
        ], $token);

        $this->assertSame($idsAvant, array_column($seconde['ingredients'], 'id'), "Les ids ne doivent pas churner");
        $this->assertSame(9, $seconde['ingredients'][0]['quantite']);
    }

    public function testUnIngredientAbsentEstSupprime(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);
        $metierId = $this->creerMetier('Verrier', 'craft');
        $sable = $this->creerObjet('Sable');
        $cendre = $this->creerObjet('Cendre');

        $premiere = $this->sauvegarderRecette($client, $token, $metierId, [
            ['objetId' => $sable, 'quantite' => 4],
            ['objetId' => $cendre, 'quantite' => 2],
        ]);

        $seconde = $this->jsonRequest($client, '/api/artisanat/editor/recette/save', [
            'recette' => $premiere['recette'],
            'produit' => $premiere['produit'],
            'ingredients' => [['id' => $premiere['ingredients'][0]['id'], 'objetId' => $sable, 'quantite' => 4]],
        ], $token);

        $this->assertCount(1, $seconde['ingredients']);
    }

    public function testUnIngredientSansItemEstRefuse(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);
        $metierId = $this->creerMetier('Verrier', 'craft');

        $reponse = $this->jsonRequest($client, '/api/artisanat/editor/recette/save', [
            'recette' => ['nom' => 'Vitre' . uniqid(), 'metierId' => $metierId, 'niveauRequis' => 1,
                          'difficulte' => 1, 'tempsSecondes' => 60, 'experienceMetier' => 10, 'actif' => true],
            'produit' => [],
            'ingredients' => [['quantite' => 3]],
        ], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('objet, un équipement ou un consommable', $reponse['error']);
    }

    /** Supprimer une recette qu'un joueur a lancée casserait sa fabrication. */
    public function testSupprimerUneRecetteAvecDesCommandesEstRefuse(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);
        $metierId = $this->creerMetier('Verrier', 'craft');
        $sable = $this->creerObjet('Sable');
        $recette = $this->sauvegarderRecette($client, $token, $metierId, [['objetId' => $sable, 'quantite' => 1]]);
        $recetteId = $recette['recette']['id'];

        $userId = (int)$this->sqlFetchOne('SELECT id FROM user ORDER BY id LIMIT 1');
        $this->sql("INSERT INTO craft_commande (user_id, recette_id, mode, statut, lancee_at, pret_at, ingredients)
                    VALUES (?, ?, 'rapide', 'en_cours', NOW(), NOW(), '[]')", [$userId, $recetteId]);

        $reponse = $this->jsonRequest($client, '/api/artisanat/editor/recette/delete', ['id' => $recetteId], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Désactivez-la', $reponse['error']);
    }

    /** Un métier encore employé ne se supprime pas en silence. */
    public function testSupprimerUnMetierEmployeEstRefuse(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);
        $metierId = $this->creerMetier('Verrier', 'craft');
        $sable = $this->creerObjet('Sable');
        $this->sauvegarderRecette($client, $token, $metierId, [['objetId' => $sable, 'quantite' => 1]]);

        $reponse = $this->jsonRequest($client, '/api/artisanat/editor/metier/delete', ['id' => $metierId], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('encore employé', $reponse['error']);
    }

    /** Retirer le métier d'un objet le déclasse en objet ordinaire, sans le supprimer. */
    public function testDetacherLeMetierDUneRessourceLaRendOrdinaire(): void
    {
        $client = static::createClient();
        $token = $this->admin($client);
        $metierId = $this->creerMetier('Verrier', 'craft');
        $objetId = $this->creerObjet('Sable');

        $this->jsonRequest($client, '/api/artisanat/editor/ressource/save', [
            'id' => $objetId, 'nom' => 'Sable fin', 'metierId' => $metierId, 'niveauRessource' => 5,
        ], $token);
        $this->assertSame($metierId, (int)$this->sqlFetchOne('SELECT metier_id FROM objet WHERE id = ?', [$objetId]));

        $this->jsonRequest($client, '/api/artisanat/editor/ressource/save', [
            'id' => $objetId, 'nom' => 'Sable fin', 'metierId' => null, 'niveauRessource' => 0,
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertNull($this->sqlFetchOne('SELECT metier_id FROM objet WHERE id = ?', [$objetId]));
        $this->assertNotNull($this->sqlFetchOne('SELECT id FROM objet WHERE id = ?', [$objetId]), "L'objet survit");
    }

    /* ------------------------------------------------------------------ */

    private function sauvegarderRecette(KernelBrowser $client, string $token, int $metierId, array $ingredients): array
    {
        $reponse = $this->jsonRequest($client, '/api/artisanat/editor/recette/save', [
            'recette' => ['nom' => 'Recette' . uniqid(), 'metierId' => $metierId, 'niveauRequis' => 1,
                          'difficulte' => 2, 'tempsSecondes' => 90, 'experienceMetier' => 15, 'actif' => true],
            'produit' => [],
            'ingredients' => $ingredients,
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->recettesCreees[] = $reponse['recette']['id'];

        return $reponse;
    }

    private function creerMetier(string $nom, string $famille): int
    {
        $this->sql('INSERT INTO metier (nom, description, icone, famille, niveau_max) VALUES (?, NULL, NULL, ?, 200)',
            [$nom . uniqid(), $famille]);
        $id = (int)$this->sqlFetchOne('SELECT MAX(id) FROM metier');
        $this->metiersCrees[] = $id;

        return $id;
    }

    private function creerObjet(string $nom): int
    {
        $this->sql('INSERT INTO objet (name, description, prix_vente, image, niveau_ressource) VALUES (?, NULL, 5, NULL, 0)',
            [$nom . uniqid()]);
        $id = (int)$this->sqlFetchOne('SELECT MAX(id) FROM objet');
        $this->objetsCrees[] = $id;

        return $id;
    }

    private function admin(KernelBrowser $client): string
    {
        $user = $this->registerUser($client);
        $this->sql('UPDATE user SET roles = ? WHERE id = ?', [json_encode(['ROLE_ADMIN']), $user['id']]);

        return $this->login($client, $user);
    }

    private function joueurSimple(KernelBrowser $client): string
    {
        return $this->login($client, $this->registerUser($client));
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
        $unique = uniqid('artmk', true);
        $user = [
            'pseudo' => 'Amk' . substr(md5($unique), 0, 10),
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
