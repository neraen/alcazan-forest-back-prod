<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Groupe éphémère de donjon de bout en bout sur chusei_test.
 *
 * Contenu utilisé : donjon 1 « Donjon Scintillant », porte = case 1979 de la carte 6,
 * salle d'entrée = carte 8, groupe max 5, niveau minimum 15.
 *
 * La propriété structurante vérifiée ici : **un lobby ne consomme aucun verrou**.
 * Composer, hésiter et se disperser doit laisser la journée intacte.
 */
class DonjonGroupeApiFunctionalTest extends WebTestCase
{
    private const CASE_PORTE = 1979;
    private const CARTE_ENTREE = 8;

    public function testUnLobbyNeConsommeAucunVerrou(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);

        $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $token);
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_verrou WHERE user_id = ?', [$user['id']]),
            "Former un groupe ne doit pas consommer le donjon du jour"
        );

        $this->jsonRequest($client, '/api/donjon/groupe/quitter', [], $token);

        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_verrou WHERE user_id = ?', [$user['id']]),
            "Se disperser non plus"
        );
    }

    public function testLancerFaitEntrerToutLeGroupeDansUneSeuleInstance(): void
    {
        $client = static::createClient();
        [$meneur, $tokenMeneur] = $this->joueurDevantLaPorte($client);
        [$compagnon, $tokenCompagnon] = $this->joueurDevantLaPorte($client);

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $groupeId = $groupe['groupe']['id'];

        $rejoint = $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupeId], $tokenCompagnon);
        $this->assertCount(2, $rejoint['groupe']['membres']);

        $lance = $this->jsonRequest($client, '/api/donjon/groupe/lancer', [], $tokenMeneur);
        $this->assertResponseIsSuccessful();

        $instanceId = $lance['instance']['id'];
        $this->assertSame(
            2,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_instance_membre WHERE instance_id = ?', [$instanceId])
        );
        $this->assertSame(
            1,
            $this->instancesDe([$meneur['id'], $compagnon['id']]),
            "Une seule instance pour tout le groupe"
        );

        // Les deux sont dans la salle d'entrée, sur des cases DIFFÉRENTES.
        foreach ([$meneur, $compagnon] as $joueur) {
            $this->assertSame(
                self::CARTE_ENTREE,
                (int)$this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$joueur['id']])
            );
            $this->assertNull(
                $this->sqlFetchOne('SELECT id FROM carte_carreau WHERE joueur_id = ?', [$joueur['id']]),
                "En instance, aucune case de décor ne porte le joueur"
            );
        }

        $this->assertNotSame(
            $this->positionDe($meneur['id']),
            $this->positionDe($compagnon['id']),
            "Deux membres ne doivent pas atterrir sur la même case"
        );

        // Chacun a son verrou, posés ensemble au lancement.
        $this->assertSame(
            2,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_verrou WHERE instance_id = ?', [$instanceId])
        );
    }

    public function testLesMembresDUnMemeGroupeSeVoientDansLaSalle(): void
    {
        $client = static::createClient();
        [$meneur, $tokenMeneur] = $this->joueurDevantLaPorte($client);
        [$compagnon, $tokenCompagnon] = $this->joueurDevantLaPorte($client);

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupe['groupe']['id']], $tokenCompagnon);
        $this->jsonRequest($client, '/api/donjon/groupe/lancer', [], $tokenMeneur);

        $vue = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => self::CARTE_ENTREE], $tokenMeneur);
        $pseudosVus = array_values(array_filter(array_column($vue['cases'], 'pseudo')));

        sort($pseudosVus);
        $attendus = [$meneur['pseudo'], $compagnon['pseudo']];
        sort($attendus);

        $this->assertSame($attendus, $pseudosVus, "Les membres du groupe se voient entre eux");
    }

    public function testSeulLeMeneurPeutLancer(): void
    {
        $client = static::createClient();
        [$meneur, $tokenMeneur] = $this->joueurDevantLaPorte($client);
        [$compagnon, $tokenCompagnon] = $this->joueurDevantLaPorte($client);

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupe['groupe']['id']], $tokenCompagnon);

        $refus = $this->jsonRequest($client, '/api/donjon/groupe/lancer', [], $tokenCompagnon);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('peut lancer', $refus['error']);
        $this->assertSame(0, $this->instancesDe([$meneur['id'], $compagnon['id']]));
    }

    public function testUnGroupeNeDepassePasLaTailleMax(): void
    {
        $client = static::createClient();
        [, $tokenMeneur] = $this->joueurDevantLaPorte($client);

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $groupeId = $groupe['groupe']['id'];

        // Le donjon seedé accepte 5 joueurs : 4 compagnons passent, le 5e est refusé.
        for ($i = 0; $i < 4; $i++) {
            [, $token] = $this->joueurDevantLaPorte($client);
            $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupeId], $token);
            $this->assertResponseIsSuccessful();
        }

        [, $tokenDeTrop] = $this->joueurDevantLaPorte($client);
        $refus = $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupeId], $tokenDeTrop);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('complet', $refus['error']);
        $this->assertSame(
            5,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_groupe_membre WHERE groupe_id = ?', [$groupeId])
        );
    }

    public function testUnInscritDejaVerrouilleEmpecheLeLancement(): void
    {
        $client = static::createClient();
        [$meneur, $tokenMeneur] = $this->joueurDevantLaPorte($client);
        [$compagnon, $tokenCompagnon] = $this->joueurDevantLaPorte($client);

        // Le compagnon a déjà fait le donjon aujourd'hui (entrée solo plus tôt).
        $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => self::CASE_PORTE,
            'targetMapId' => self::CARTE_ENTREE,
            'targetWrap' => 3062,
        ], $tokenCompagnon);
        $this->assertSame(1, (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_verrou WHERE user_id = ?', [$compagnon['id']]));

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupe['groupe']['id']], $tokenCompagnon);

        $refus = $this->jsonRequest($client, '/api/donjon/groupe/lancer', [], $tokenMeneur);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("déjà fait ce donjon", $refus['error']);
        $this->assertSame(
            1,
            $this->instancesDe([$meneur['id'], $compagnon['id']]),
            "Le refus ne doit créer aucune instance de groupe (seule celle du solo existe)"
        );
    }

    public function testDissoudreLeGroupeQuandLeMeneurPart(): void
    {
        $client = static::createClient();
        [, $tokenMeneur] = $this->joueurDevantLaPorte($client);
        [, $tokenCompagnon] = $this->joueurDevantLaPorte($client);

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $groupeId = $groupe['groupe']['id'];
        $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupeId], $tokenCompagnon);

        $this->jsonRequest($client, '/api/donjon/groupe/quitter', [], $tokenMeneur);

        $this->assertSame(
            'annule',
            $this->sqlFetchOne('SELECT statut FROM donjon_groupe WHERE id = ?', [$groupeId])
        );
        $orphelin = $this->jsonRequest($client, '/api/donjon/groupe/courant', [], $tokenCompagnon);
        $this->assertNull($orphelin['groupe'], "Le compagnon ne doit plus être dans un groupe");
    }

    public function testLaPorteDecritLeDonjonEtLEtatDuVerrou(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueurDevantLaPorte($client);

        $porte = $this->jsonRequest($client, '/api/donjon/porte', ['carteCarreauId' => self::CASE_PORTE], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('Donjon Scintillant', $porte['donjon']['nom']);
        $this->assertSame(5, $porte['donjon']['tailleGroupeMax']);
        $this->assertFalse($porte['verrou']['consomme']);
        $this->assertTrue($porte['peutEntrerSeul']);
        $this->assertNull($porte['monGroupe']);
    }

    public function testLaPorteExigeLaProximite(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);
        $this->sql('UPDATE user SET case_abscisse = 0, case_ordonnee = 0 WHERE id = ?', [$user['id']]);

        $refus = $this->jsonRequest($client, '/api/donjon/porte', ['carteCarreauId' => self::CASE_PORTE], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('trop loin', $refus['error']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Compte les instances DISTINCTES où ces joueurs sont membres. La base de test n'est
     * pas remise à zéro entre les tests : un COUNT global compterait les instances des
     * cas précédents.
     */
    private function instancesDe(array $userIds): int
    {
        $places = implode(',', array_fill(0, count($userIds), '?'));

        return (int)$this->sqlFetchOne(
            "SELECT COUNT(DISTINCT instance_id) FROM donjon_instance_membre WHERE user_id IN ($places)",
            $userIds
        );
    }

    private function positionDe(int $userId): string
    {
        return $this->sqlFetchOne('SELECT CONCAT(case_abscisse, ":", case_ordonnee) FROM user WHERE id = ?', [$userId]);
    }

    /** @return array{0: array, 1: string} */
    private function joueurDevantLaPorte(KernelBrowser $client, int $niveau = 20): array
    {
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE niveau_joueur SET niveau_id = ? WHERE user_id = ?', [$niveau, $user['id']]);
        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$user['id']]);
        $this->sql('UPDATE user SET map_id = 6, case_abscisse = 10, case_ordonnee = 2 WHERE id = ?', [$user['id']]);

        return [$user, $token];
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
        $unique = uniqid('groupe', true);
        $user = [
            'pseudo' => 'Groupe' . substr(md5($unique), 0, 10),
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
