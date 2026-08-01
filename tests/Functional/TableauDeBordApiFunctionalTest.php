<?php

namespace App\Tests\Functional;

use App\Enum\TypeEvenement;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tableau de bord et fiche joueur.
 *
 * L'essentiel de ce fichier porte sur la classification des flux monétaires : c'est la seule
 * partie du tableau de bord qui applique une règle de DOMAINE que le SQL ne connaît pas, et
 * donc la seule qui puisse être fausse sans que rien ne plante. Confondre un transfert entre
 * joueurs avec de la création monétaire ferait conclure à une inflation qui n'existe pas.
 */
class TableauDeBordApiFunctionalTest extends WebTestCase
{
    /* ---------------------------------------------------------------- */
    /* Sécurité                                                          */
    /* ---------------------------------------------------------------- */

    public function testUnJoueurOrdinaireNAccedePasAuTableauDeBord(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerUser($client));

        $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUnJoueurOrdinaireNAccedePasAUneFiche(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/admin/stats/joueur', ['userId' => $user['id']], $token);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    /* ---------------------------------------------------------------- */
    /* Classification des flux monétaires                                 */
    /* ---------------------------------------------------------------- */

    /**
     * Chaque type qui déplace de l'or doit être classé, et aucun ne peut l'être deux fois.
     * Un type ajouté sans être classé disparaîtrait silencieusement du tableau de bord.
     */
    public function testChaqueTypePorteurDOrEstClasseUneSeuleFois(): void
    {
        $classes = [];
        foreach (['creation', 'destruction', 'transfert'] as $flux) {
            foreach (TypeEvenement::parFlux($flux) as $type) {
                $this->assertArrayNotHasKey($type->value, $classes, "{$type->value} est classé deux fois.");
                $classes[$type->value] = $flux;
            }
        }

        // Les types qu'on sait porteurs d'or DOIVENT être couverts.
        foreach ([TypeEvenement::VENTE_PNJ, TypeEvenement::ACHAT_PNJ, TypeEvenement::HDV_ACHAT,
                  TypeEvenement::ECHANGE_CONCLU, TypeEvenement::HDV_DEPOT, TypeEvenement::QUETE_TERMINEE] as $type) {
            $this->assertArrayHasKey($type->value, $classes, "{$type->value} n'est pas classé.");
        }

        $this->assertNull(
            TypeEvenement::MONSTRE_TUE->fluxMonetaire(),
            "Un monstre tué ne déplace pas d'or : le classer fausserait la masse monétaire."
        );
    }

    /** Un échange entre joueurs est un TRANSFERT : il ne crée ni ne détruit d'or. */
    public function testUnEchangeNEstNiCreationNiDestruction(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));
        $a = $this->registerUser($client);
        $b = $this->registerUser($client);

        $avant = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token)['economie'];

        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, cible_user_id, quantite, montant_or, cree_le)
             VALUES ('echange_conclu', ?, ?, 1, 500, NOW())",
            [$a['id'], $b['id']]
        );

        $apres = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token)['economie'];

        $this->assertSame($avant['orCree'], $apres['orCree'], "Un échange ne crée pas d'or.");
        $this->assertSame($avant['orDetruit'], $apres['orDetruit'], "Un échange ne détruit pas d'or.");
        $this->assertSame($avant['orTransfere'] + 500, $apres['orTransfere']);
    }

    /**
     * Pour un dépôt à l'hôtel des ventes, l'or détruit ce sont les FRAIS — jamais le prix
     * demandé, qui n'est ni créé ni détruit. C'est le puits monétaire documenté en §20.
     */
    public function testSeulsLesFraisDeDepotSontDetruits(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));
        $vendeur = $this->registerUser($client);

        $avant = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token)['economie'];

        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, quantite, montant_or, contexte, cree_le)
             VALUES ('hdv_depot', ?, 1, 10000, ?, NOW())",
            [$vendeur['id'], json_encode(['fraisDepot' => 250])]
        );

        $apres = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token)['economie'];

        $this->assertSame(
            $avant['fraisDepot'] + 250,
            $apres['fraisDepot'],
            'Les frais sont lus dans le contexte, pas dans montant_or.'
        );
        $this->assertSame(
            $avant['orDetruit'] + 250,
            $apres['orDetruit'],
            "Le prix demandé (10 000) ne doit PAS compter comme de l'or détruit."
        );
    }

    public function testLeSoldeEstLEcartEntreCreationEtDestruction(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));

        $economie = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token)['economie'];

        $this->assertSame($economie['orCree'] - $economie['orDetruit'], $economie['solde']);
    }

    /* ---------------------------------------------------------------- */
    /* Activité                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * La série couvre TOUS les jours de la fenêtre, y compris les vides : une courbe qui
     * saute les jours sans événement ment sur la forme de l'activité.
     */
    public function testLaSerieCouvreChaqueJourDeLaFenetre(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));

        $donnees = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token);
        $activite = $donnees['activite'];

        $this->assertCount($donnees['fenetreJours'], $activite['jours']);
        foreach ($activite['series'] as $serie) {
            $this->assertCount(
                $donnees['fenetreJours'],
                $serie['points'],
                "La série {$serie['cle']} doit avoir un point par jour."
            );
            $this->assertSame(array_sum($serie['points']), $serie['total']);
        }
    }

    public function testUneConnexionCompteCommeJoueurActif(): void
    {
        $client = static::createClient();
        $observe = $this->registerUser($client);
        $this->login($client, $observe);

        $token = $this->login($client, $this->registerAdmin($client));
        $activite = $this->jsonRequest($client, '/api/admin/stats/tableau-de-bord', [], $token)['activite'];

        $this->assertGreaterThanOrEqual(1, $activite['actifs24h']);
        $this->assertGreaterThanOrEqual($activite['actifs24h'], $activite['actifs7j'], '7 jours englobe 24 h.');
    }

    /* ---------------------------------------------------------------- */
    /* Fiche joueur                                                      */
    /* ---------------------------------------------------------------- */

    public function testLaFicheRemonteCeQuIlAFaitEtSubi(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));
        $tueur = $this->registerUser($client);
        $victime = $this->registerUser($client);

        $this->sql(
            "INSERT INTO evenement_jeu (type, acteur_id, cible_user_id, quantite, montant_or, cree_le)
             VALUES ('mort_joueur', ?, ?, 1, 0, NOW())",
            [$tueur['id'], $victime['id']]
        );

        foreach ([$tueur, $victime] as $joueur) {
            $fiche = $this->jsonRequest($client, '/api/admin/stats/joueur', ['userId' => $joueur['id']], $token);
            $this->assertResponseIsSuccessful();
            $this->assertSame($joueur['pseudo'], $fiche['joueur']['pseudo']);
            $this->assertSame(
                1,
                $fiche['evenementsTotal'],
                "La fiche doit remonter ce que le joueur a fait ET subi."
            );
        }
    }

    public function testLaFicheDUnJoueurInconnuEstUne404(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));

        $this->jsonRequest($client, '/api/admin/stats/joueur', ['userId' => 99999999], $token);

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testLaListeDesJoueursPorteDeQuoiLesDistinguer(): void
    {
        $client = static::createClient();
        $token = $this->login($client, $this->registerAdmin($client));

        $reponse = $this->jsonRequest($client, '/api/admin/stats/joueurs', [], $token);
        $this->assertResponseIsSuccessful();
        $this->assertNotEmpty($reponse['joueurs']);

        $premier = $reponse['joueurs'][0];
        foreach (['id', 'pseudo', 'niveau', 'classe', 'money', 'derniereConnexion', 'horsClassement'] as $cle) {
            $this->assertArrayHasKey($cle, $premier);
        }
    }

    /* ---------------------------------------------------------------- */

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('tdb', true);
        $user = [
            'pseudo' => 'Tdb' . substr(md5($unique), 0, 10),
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
