<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Atelier : lancement, résolution paresseuse, retrait, recyclage, annulation.
 *
 * Le test crée SES métiers, SES ressources et SES recettes puis nettoie : ce sont des
 * données de contenu, un autre test ne doit pas en hériter.
 */
class CraftApiFunctionalTest extends WebTestCase
{
    private array $metiersCrees = [];
    private array $recettesCreees = [];
    private array $objetsCrees = [];
    private array $recompensesCreees = [];

    protected function tearDown(): void
    {
        foreach ($this->recettesCreees as $recetteId) {
            $this->sql('DELETE FROM craft_commande WHERE recette_id = ?', [$recetteId]);
            $this->sql('DELETE FROM recette_ingredient WHERE recette_id = ?', [$recetteId]);
            $this->sql('DELETE FROM recette WHERE id = ?', [$recetteId]);
        }
        foreach ($this->recompensesCreees as $recompenseId) {
            $this->sql('DELETE FROM recompense WHERE id = ?', [$recompenseId]);
        }
        foreach ($this->objetsCrees as $objetId) {
            $this->sql('DELETE FROM inventaire_objet WHERE objet_id = ?', [$objetId]);
            $this->sql('DELETE FROM objet WHERE id = ?', [$objetId]);
        }
        foreach ($this->metiersCrees as $metierId) {
            $this->sql('DELETE FROM joueur_metier WHERE metier_id = ?', [$metierId]);
            $this->sql('DELETE FROM metier WHERE id = ?', [$metierId]);
        }
        parent::tearDown();
    }

    public function testUnCycleCompletDeFabrication(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 4]], tempsSecondes: 100);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 10);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);

        $this->assertResponseIsSuccessful();
        $commandeId = $lancement['commande']['id'];
        $this->assertFalse($lancement['commande']['prete']);
        $this->assertSame(
            6,
            $this->quantitePossedee($user['id'], $ingredient),
            'Les ingrédients sont CONSOMMÉS au lancement, pas réservés'
        );

        // Résolution paresseuse : on avance l'horloge de la commande plutôt que d'attendre.
        $this->rendrePrete($commandeId);

        $retrait = $this->jsonRequest($client, '/api/craft/retirer', ['commandeId' => $commandeId], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->quantitePossedee($user['id'], $produit), 'La sortie arrive dans le sac');
        $this->assertSame(
            7,
            $this->quantitePossedee($user['id'], $ingredient),
            'Le recyclage rend 30 % de 4 = 1 exemplaire'
        );
        $this->assertSame([], $retrait['commandes'], "La file d'attente est vide après le retrait");
    }

    public function testRetirerAvantLaFinEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $recetteId = $this->recetteJouable($user['id'], $ingredient, $produit, tempsSecondes: 3600);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);
        $reponse = $this->jsonRequest($client, '/api/craft/retirer',
            ['commandeId' => $lancement['commande']['id']], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("n'est pas terminée", $reponse['error']);
    }

    /** Idempotence stricte : un double retrait ne duplique pas la sortie. */
    public function testRetirerDeuxFoisEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $recetteId = $this->recetteJouable($user['id'], $ingredient, $produit);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'rapide'], $token);
        $commandeId = $lancement['commande']['id'];
        $this->rendrePrete($commandeId);

        $this->jsonRequest($client, '/api/craft/retirer', ['commandeId' => $commandeId], $token);
        $this->assertResponseIsSuccessful();

        $rejeu = $this->jsonRequest($client, '/api/craft/retirer', ['commandeId' => $commandeId], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('déjà', $rejeu['error']);
        $this->assertSame(1, $this->quantitePossedee($user['id'], $produit), 'La sortie n\'est distribuée qu\'une fois');
    }

    /**
     * Le cœur de l'instantané : éditer la recette pendant la cuisson ne doit pas changer
     * ce qui est rendu au recyclage.
     */
    public function testLeRecyclageRendLInstantaneEtNonLaRecetteModifiee(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $autre = $this->creerObjet('Racine');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 10]]);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 10);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);
        $commandeId = $lancement['commande']['id'];

        // La recette est réécrite pendant la cuisson : autre ingrédient, autre quantité.
        $this->sql('UPDATE recette_ingredient SET objet_id = ?, quantite = 100 WHERE recette_id = ?',
            [$autre, $recetteId]);

        $this->rendrePrete($commandeId);
        $this->jsonRequest($client, '/api/craft/retirer', ['commandeId' => $commandeId], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(3, $this->quantitePossedee($user['id'], $ingredient), '30 % de 10 = 3, depuis l\'instantané');
        $this->assertSame(0, $this->quantitePossedee($user['id'], $autre), 'Rien ne vient de la recette réécrite');
    }

    public function testLaFabricationExpeditiveNeRendRien(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 10]]);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 10);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'rapide'], $token);
        $this->rendrePrete($lancement['commande']['id']);
        $this->jsonRequest($client, '/api/craft/retirer', ['commandeId' => $lancement['commande']['id']], $token);

        $this->assertSame(0, $this->quantitePossedee($user['id'], $ingredient));
    }

    /** La fabrication expéditive est quatre fois plus rapide. */
    public function testLeModeChangeLeTempsDeProduction(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 1]], tempsSecondes: 400);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 10);

        $soignee = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);
        $rapide = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'rapide'], $token);

        $duree = fn (int $id) => (int)$this->sqlFetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, lancee_at, pret_at) FROM craft_commande WHERE id = ?', [$id]);

        $this->assertSame(400, $duree($soignee['commande']['id']));
        $this->assertSame(100, $duree($rapide['commande']['id']));
    }

    public function testDesIngredientsManquantsSontRefusesAvecLeurNom(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('SaugeIntrouvable');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 5]]);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 2);

        $reponse = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('SaugeIntrouvable', $reponse['error']);
        $this->assertSame(2, $this->quantitePossedee($user['id'], $ingredient), 'Un refus ne débite rien');
    }

    /** Une ressource engagée dans un échange n'est pas craftable. */
    public function testUneRessourceReserveeNEstPasCraftable(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 5]]);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 5);
        $this->sql("INSERT INTO reservation_ressource (user_id, type, item_id, quantite, origine, origine_id, created_at)
                    VALUES (?, 'objet', ?, 3, 'echange', 999999, NOW())", [$user['id'], $ingredient]);

        $reponse = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);

        $this->sql('DELETE FROM reservation_ressource WHERE origine_id = 999999');

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('2 disponibles', $reponse['error']);
    }

    public function testUnNiveauDeMetierInsuffisantEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Forgeron', 'craft');
        $ingredient = $this->creerObjet('Fer');
        $produit = $this->creerObjet('Arc');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 1]], niveauRequis: 10);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 5);

        $reponse = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('niveau 10', $reponse['error']);
    }

    public function testSansLeMetierLaRecetteEstRefusee(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Forgeron', 'craft');
        $ingredient = $this->creerObjet('Fer');
        $produit = $this->creerObjet('Arc');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 1]]);
        $this->donnerDesObjets($user['id'], $ingredient, 5);

        $reponse = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('appris le métier', $reponse['error']);
    }

    public function testLePlafondDeCommandesSimultaneesEstApplique(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 1]], tempsSecondes: 3600);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 20);

        for ($i = 0; $i < 3; $i++) {
            $this->jsonRequest($client, '/api/craft/lancer', ['recetteId' => $recetteId, 'mode' => 'rapide'], $token);
            $this->assertResponseIsSuccessful();
        }

        $reponse = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'rapide'], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('3 fabrications', $reponse['error']);
    }

    public function testAnnulerRendLesIngredientsALIdentique(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 7]], tempsSecondes: 3600);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 7);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);
        $this->assertSame(0, $this->quantitePossedee($user['id'], $ingredient));

        $this->jsonRequest($client, '/api/craft/annuler', ['commandeId' => $lancement['commande']['id']], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(7, $this->quantitePossedee($user['id'], $ingredient), 'Rendus à 100 %, pas au taux de recyclage');
    }

    /** Après `pretAt`, l'objet est fait : on ne peut plus lui préférer ses matériaux. */
    public function testAnnulerUneFabricationTermineeEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $recetteId = $this->recetteJouable($user['id'], $ingredient, $produit);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);
        $this->rendrePrete($lancement['commande']['id']);

        $reponse = $this->jsonRequest($client, '/api/craft/annuler',
            ['commandeId' => $lancement['commande']['id']], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('terminée', $reponse['error']);
    }

    /** L'atelier ne montre que les recettes des métiers réellement appris. */
    public function testLAtelierNeMontreQueLesMetiersAppris(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $appris = $this->creerMetier('Alchimiste', 'craft');
        $inconnu = $this->creerMetier('Bijoutier', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $visible = $this->creerRecette($appris, $produit, [[$ingredient, 1]]);
        $this->creerRecette($inconnu, $produit, [[$ingredient, 1]]);
        $this->donnerLeMetier($user['id'], $appris);

        $atelier = $this->jsonRequest($client, '/api/craft/atelier', [], $token);

        $ids = array_column($atelier['recettes'], 'id');
        $this->assertContains($visible, $ids);
        $this->assertCount(1, $ids);
    }

    /**
     * La page Artisanat montre une photo par recette et par ingrédient : le payload doit
     * porter l'identité visuelle complète (famille, id, nom de fichier). Le nom de fichier
     * est renvoyé BRUT — les dossiers d'images vivent côté front (`itemUtils.itemImage`),
     * et deux sources de vérité finiraient par diverger.
     *
     * `lanceeAt` est vérifiée ici pour la même raison : sans elle, le front ne connaît pas
     * la durée totale (le mode l'a multipliée) et ne peut pas tracer d'avancement.
     */
    public function testLAtelierDecritLImageDuProduitEtDesIngredients(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $this->sql('UPDATE objet SET image = ? WHERE id = ?', ['potion.png', $produit]);
        $this->sql('UPDATE objet SET image = ? WHERE id = ?', ['sauge.png', $ingredient]);
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 2]]);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->donnerDesObjets($user['id'], $ingredient, 2);

        $atelier = $this->jsonRequest($client, '/api/craft/atelier', [], $token);
        $recette = $atelier['recettes'][0];

        $this->assertSame('objet', $recette['produit']['type']);
        $this->assertSame($produit, $recette['produit']['itemId']);
        $this->assertSame('potion.png', $recette['produit']['image']);
        $this->assertNull($recette['produit']['position'], "Un objet n'a pas de dossier de position");
        $this->assertSame('sauge.png', $recette['ingredients'][0]['image']);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);

        $this->assertArrayHasKey('lanceeAt', $lancement['commande']);
        $this->assertSame('potion.png', $lancement['commande']['produit']['image']);
    }

    public function testUneCommandeDUnAutreJoueurEstIntrouvable(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur();
        [, $tokenIntrus] = $this->joueur();
        $recetteId = $this->recetteJouable($user['id'], $ingredient, $produit);

        $lancement = $this->jsonRequest($client, '/api/craft/lancer',
            ['recetteId' => $recetteId, 'mode' => 'recyclage'], $token);
        $reponse = $this->jsonRequest($client, '/api/craft/retirer',
            ['commandeId' => $lancement['commande']['id']], $tokenIntrus);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("n'existe pas", $reponse['error']);
    }

    /* ------------------------------------------------------------------ */

    /** Recette d'alchimie jouable par le joueur, avec ses ingrédients en poche. */
    private function recetteJouable(int $userId, ?int &$ingredient, ?int &$produit, int $tempsSecondes = 100): int
    {
        $metierId = $this->creerMetier('Alchimiste', 'craft');
        $ingredient = $this->creerObjet('Sauge');
        $produit = $this->creerObjet('Potion');
        $recetteId = $this->creerRecette($metierId, $produit, [[$ingredient, 2]], tempsSecondes: $tempsSecondes);
        $this->donnerLeMetier($userId, $metierId);
        $this->donnerDesObjets($userId, $ingredient, 10);

        return $recetteId;
    }

    /** Fait passer `pretAt` dans le passé : la résolution étant paresseuse, cela suffit. */
    private function rendrePrete(int $commandeId): void
    {
        $this->sql('UPDATE craft_commande SET pret_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$commandeId]);
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

    /** @param array<int, array{0: int, 1: int}> $ingredients [objetId, quantite] */
    private function creerRecette(
        int $metierId,
        int $produitId,
        array $ingredients,
        int $niveauRequis = 1,
        int $tempsSecondes = 100
    ): int {
        $this->sql('INSERT INTO recompense (objet_id, money, experience, quantity) VALUES (?, NULL, NULL, 1)', [$produitId]);
        $recompenseId = (int)$this->sqlFetchOne('SELECT MAX(id) FROM recompense');
        $this->recompensesCreees[] = $recompenseId;

        $this->sql('INSERT INTO recette (nom, description, metier_id, niveau_requis, difficulte, temps_secondes,
                    recompense_id, experience_metier, actif) VALUES (?, NULL, ?, ?, 1, ?, ?, 10, 1)',
            ['Recette' . uniqid(), $metierId, $niveauRequis, $tempsSecondes, $recompenseId]);
        $recetteId = (int)$this->sqlFetchOne('SELECT MAX(id) FROM recette');
        $this->recettesCreees[] = $recetteId;

        foreach ($ingredients as [$objetId, $quantite]) {
            $this->sql('INSERT INTO recette_ingredient (recette_id, objet_id, quantite) VALUES (?, ?, ?)',
                [$recetteId, $objetId, $quantite]);
        }

        return $recetteId;
    }

    private function donnerLeMetier(int $userId, int $metierId, int $niveau = 1): void
    {
        $this->sql('INSERT INTO joueur_metier (user_id, metier_id, niveau, experience, appris_at) VALUES (?, ?, ?, 0, NOW())',
            [$userId, $metierId, $niveau]);
    }

    private function donnerDesObjets(int $userId, int $objetId, int $quantite): void
    {
        $inventaireId = (int)$this->sqlFetchOne('SELECT id FROM inventaire WHERE user_id = ?', [$userId]);
        $this->sql('INSERT INTO inventaire_objet (inventaire_id, objet_id, quantity) VALUES (?, ?, ?)',
            [$inventaireId, $objetId, $quantite]);
    }

    private function quantitePossedee(int $userId, int $objetId): int
    {
        return (int)$this->sqlFetchOne(
            'SELECT COALESCE(SUM(io.quantity), 0) FROM inventaire_objet io
             JOIN inventaire i ON i.id = io.inventaire_id WHERE i.user_id = ? AND io.objet_id = ?',
            [$userId, $objetId]
        );
    }

    /** @return array{0: array, 1: string} */
    private function joueur(): array
    {
        $client = static::getClient();
        $user = $this->registerUser($client);

        return [$user, $this->login($client, $user)];
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
        $unique = uniqid('craft', true);
        $user = [
            'pseudo' => 'Crf' . substr(md5($unique), 0, 10),
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
