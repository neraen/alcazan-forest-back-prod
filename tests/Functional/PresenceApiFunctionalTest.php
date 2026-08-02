<?php

namespace App\Tests\Functional;

use App\Config\PresenceConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Présence des joueurs : la pastille verte / grise de l'étiquette de survol.
 *
 * Ce que ces tests verrouillent, c'est que la RÈGLE reste au serveur. Le front reçoit un
 * booléen déjà tranché : il ne connaît ni la fenêtre de présence
 * (`PresenceConfig::FENETRE_EN_LIGNE_MINUTES`) ni l'horloge du serveur, et une date brute
 * l'obligerait à deviner les deux.
 */
class PresenceApiFunctionalTest extends WebTestCase
{
    /** Une requête authentifiée suffit à rendre son auteur visible comme présent. */
    public function testJouerMetAJourLaPresence(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE user SET derniere_activite = NULL WHERE id = ?', [$user['id']]);

        $this->jsonRequest($client, '/api/joueur/data/minimal', [], $token);

        $this->assertNotNull(
            $this->sqlFetchAssoc('SELECT derniere_activite FROM user WHERE id = ?', [$user['id']])['derniere_activite'],
            "Une requête authentifiée note la présence de son auteur."
        );
    }

    /**
     * L'écriture est BORNÉE dans le temps : un déplacement déclenche plusieurs requêtes
     * authentifiées, et la présence n'a pas besoin d'être à la seconde. Sans cette borne,
     * chaque pas coûterait plusieurs UPDATE sur `user`.
     */
    public function testUneActiviteFraicheNEstPasReecriteAChaqueRequete(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Fraîche selon la config, mais volontairement décalée pour qu'une réécriture se voie.
        $decalage = (int) (PresenceConfig::RAFRAICHISSEMENT_SECONDES / 2);
        $this->sql(
            'UPDATE user SET derniere_activite = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?',
            [$decalage, $user['id']]
        );
        $avant = $this->activiteDe($user['id']);

        $this->jsonRequest($client, '/api/joueur/data/minimal', [], $token);

        $this->assertSame($avant, $this->activiteDe($user['id']), "Rien à réécrire : l'activité est fraîche.");
    }

    /* ---------------------------------------------------------------- */
    /* Ce que la carte descend                                           */
    /* ---------------------------------------------------------------- */

    /** Un joueur actif est annoncé en ligne sur la carte, aux autres joueurs. */
    public function testLaCarteAnnonceEnLigneUnJoueurActif(): void
    {
        $client = static::createClient();
        [$observateur, $token, $voisin] = $this->deuxJoueursSurLaMemeCarte($client);
        $this->sql('UPDATE user SET derniere_activite = NOW() WHERE id = ?', [$voisin['id']]);

        $case = $this->caseDuJoueur($client, $token, $voisin['id']);

        $this->assertNotNull($case, "Le voisin doit apparaître sur la carte.");
        $this->assertSame(1, (int) $case['enLigne']);
    }

    /**
     * Au-delà de la fenêtre, on cesse d'affirmer qu'il est là.
     *
     * Le test décale d'une minute de plus que la fenêtre : asserter sur la borne exacte
     * dépendrait de la seconde à laquelle la requête est exécutée.
     */
    public function testLaCarteAnnonceHorsLigneUnJoueurInactif(): void
    {
        $client = static::createClient();
        [$observateur, $token, $voisin] = $this->deuxJoueursSurLaMemeCarte($client);
        $this->sql(
            'UPDATE user SET derniere_activite = DATE_SUB(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
            [PresenceConfig::FENETRE_EN_LIGNE_MINUTES + 1, $voisin['id']]
        );

        $case = $this->caseDuJoueur($client, $token, $voisin['id']);

        $this->assertNotNull($case);
        $this->assertSame(0, (int) $case['enLigne']);
    }

    /** Un joueur qui n'a jamais rien fait n'est pas « en ligne » par défaut. */
    public function testUneActiviteInconnueNEstPasEnLigne(): void
    {
        $client = static::createClient();
        [$observateur, $token, $voisin] = $this->deuxJoueursSurLaMemeCarte($client);
        $this->sql('UPDATE user SET derniere_activite = NULL WHERE id = ?', [$voisin['id']]);

        $case = $this->caseDuJoueur($client, $token, $voisin['id']);

        $this->assertNotNull($case);
        $this->assertSame(0, (int) $case['enLigne']);
    }

    /* ---------------------------------------------------------------- */
    /* Aides                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Deux joueurs posés sur la carte 2, l'observateur et son voisin, chacun sur sa case de
     * `carte_carreau` — c'est cette colonne que lit `getAllCasesOfMap`.
     *
     * @return array{0: array, 1: string, 2: array}
     */
    private function deuxJoueursSurLaMemeCarte(KernelBrowser $client): array
    {
        $observateur = $this->registerUser($client);
        $voisin = $this->registerUser($client);
        $token = $this->login($client, $observateur);

        $this->poser($observateur['id'], 10, 10);
        $this->poser($voisin['id'], 11, 10);

        return [$observateur, $token, $voisin];
    }

    private function poser(int $userId, int $abscisse, int $ordonnee): void
    {
        $this->sql(
            'UPDATE user SET map_id = 2, case_abscisse = ?, case_ordonnee = ? WHERE id = ?',
            [$abscisse, $ordonnee, $userId]
        );
        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$userId]);
        $this->sql(
            'UPDATE carte_carreau SET joueur_id = ?
             WHERE carte_id = 2 AND abscisse = ? AND ordonnee = ? LIMIT 1',
            [$userId, $abscisse, $ordonnee]
        );
    }

    /** La case occupée par ce joueur, telle que la carte la sert à l'observateur. */
    private function caseDuJoueur(KernelBrowser $client, string $token, int $userId): ?array
    {
        $reponse = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => 2], $token);
        $this->assertResponseIsSuccessful();

        foreach ($reponse['cases'] ?? [] as $case) {
            if ((int) ($case['userId'] ?? 0) === $userId) {
                return $case;
            }
        }

        return null;
    }

    private function activiteDe(int $userId): ?string
    {
        return $this->sqlFetchAssoc('SELECT derniere_activite FROM user WHERE id = ?', [$userId])['derniere_activite'];
    }

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('pres', true);
        $user = [
            'pseudo' => 'Prs' . substr(md5($unique), 0, 10),
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

    private function sqlFetchAssoc(string $query, array $params = []): array|false
    {
        return static::getContainer()->get('doctrine')->getConnection()->fetchAssociative($query, $params);
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
