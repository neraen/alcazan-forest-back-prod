<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Instances de donjon de bout en bout sur chusei_test. S'appuie sur le contenu seedé :
 * donjon 1 « Donjon Scintillant », salles = cartes 8 (entrée) → 9 → 10 → 11 (boss) → 15
 * (trésor), porte du monde = case 1979 de la carte 6, niveau minimum 15.
 *
 * Les deux invariants vérifiés ici sont ceux qui portent tout le système :
 *  - en instance, `carte_carreau.joueur_id` n'est JAMAIS écrit (colonne OneToOne globale) ;
 *  - deux groupes occupent la même carte sans se voir ni se bloquer.
 */
class DonjonApiFunctionalTest extends WebTestCase
{
    private const DONJON_ID = 1;
    private const CASE_PORTE = 1979;   // carte 6 (10,2) -> carte 8
    private const CARTE_ENTREE = 8;
    private const WRAP_ENTREE = 3062;  // case d'arrivée sur la carte 8

    public function testEntrerCreeUneInstanceEtNeMarquePasLaCaseDuDecor(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);

        $reponse = $this->entrerDansLeDonjon($client, $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(self::CARTE_ENTREE, (int)$reponse['mapId']);
        $this->assertNotNull($reponse['instanceId'], "L'entrée doit créer une instance");

        $this->assertSame(
            self::CARTE_ENTREE,
            (int)$this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$user['id']]),
            "La position du joueur reste portée par `user`"
        );
        $this->assertNull(
            $this->sqlFetchOne('SELECT id FROM carte_carreau WHERE joueur_id = ?', [$user['id']]),
            "En instance, aucune case de décor ne doit porter le joueur"
        );
    }

    public function testLeVerrouInterditUneSecondeInstanceLeMemeJour(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);

        $premiere = $this->entrerDansLeDonjon($client, $token);
        $instanceId = (int)$premiere['instanceId'];

        // Il ressort dans le monde ouvert...
        $this->sortirDuDonjon($client, $token);
        $this->assertFalse(
            (bool)$this->sqlFetchOne(
                'SELECT present FROM donjon_instance_membre WHERE instance_id = ? AND user_id = ?',
                [$instanceId, $user['id']]
            ),
            'La sortie doit marquer le membre absent'
        );

        // ... et revient : il retrouve SON instance, pas une neuve.
        $this->sql('UPDATE user SET map_id = 6, case_abscisse = 10, case_ordonnee = 2 WHERE id = ?', [$user['id']]);
        $seconde = $this->entrerDansLeDonjon($client, $token);

        $this->assertSame($instanceId, (int)$seconde['instanceId'], "Le verrou doit rendre la MÊME instance");
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_instance WHERE leader_id = ?', [$user['id']]),
            "Aucune instance supplémentaire ne doit être créée dans la journée"
        );
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_verrou WHERE user_id = ?', [$user['id']]),
            "Un seul verrou par jour de donjon"
        );
    }

    /**
     * La porte doit dire la VÉRITÉ sur ce que le joueur peut encore faire aujourd'hui :
     * `rejoignable` pilote le bouton « Retourner dans mon expédition ». Le bug : il était
     * proposé même sur une expédition refermée, et l'entrée répondait alors « revenez
     * après 5 h » — un message de nouvelle expédition sur un bouton de retour.
     */
    public function testLaPorteDitSiLExpeditionDuJourEstEncoreRejoignable(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);
        $instanceId = (int)$this->entrerDansLeDonjon($client, $token)['instanceId'];
        $this->sortirDuDonjon($client, $token);

        // La porte n'est lisible que si l'on se tient devant : on s'y replace.
        $this->devantLaPorte($user['id']);
        $porte = $this->jsonRequest($client, '/api/donjon/porte', ['carteCarreauId' => self::CASE_PORTE], $token);
        $this->assertTrue($porte['verrou']['consomme']);
        $this->assertTrue($porte['verrou']['rejoignable'], 'Une expédition quittée reste rejoignable');

        // Durée max écoulée : l'expédition est close, plus rien avant le reset.
        $this->sql('UPDATE donjon_instance SET expire_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$instanceId]);
        $this->devantLaPorte($user['id']);
        $porte = $this->jsonRequest($client, '/api/donjon/porte', ['carteCarreauId' => self::CASE_PORTE], $token);

        $this->assertTrue($porte['verrou']['consomme']);
        $this->assertFalse(
            $porte['verrou']['rejoignable'],
            "Une expédition périmée n'est plus rejoignable, même si l'expiration n'a pas encore été constatée"
        );

        // Et l'entrée refuse bien, avec le message du reset : les deux doivent s'accorder.
        $refus = $this->entrerDansLeDonjon($client, $token);
        $this->assertArrayHasKey('message', $refus);
        $this->assertStringContainsString('refermé', $refus['message']);
    }

    public function testDeuxGroupesOccupentLaMemeSalleSansSeVoir(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurDevantLaPorte($client);
        [$deux, $tokenDeux] = $this->joueurDevantLaPorte($client);

        $instanceUn = (int)$this->entrerDansLeDonjon($client, $tokenUn)['instanceId'];
        $instanceDeux = (int)$this->entrerDansLeDonjon($client, $tokenDeux)['instanceId'];

        $this->assertNotSame($instanceUn, $instanceDeux, 'Chaque joueur obtient sa propre instance');

        // Les deux sont sur la même carte, à la même case d'arrivée : impossible dans le
        // monde ouvert (contrainte unique sur carte_carreau.joueur_id), normal en instance.
        $this->assertSame(
            (int)$this->sqlFetchOne('SELECT case_abscisse FROM user WHERE id = ?', [$un['id']]),
            (int)$this->sqlFetchOne('SELECT case_abscisse FROM user WHERE id = ?', [$deux['id']])
        );

        $cases = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => self::CARTE_ENTREE], $tokenUn);
        $pseudosVus = array_values(array_filter(array_column($cases['cases'], 'pseudo')));

        $this->assertSame([$un['pseudo']], $pseudosVus, "Le joueur ne doit voir que son propre groupe");
    }

    public function testLaVieDuBossEstPropreAChaqueInstance(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurDevantLaPorte($client);
        [$deux, $tokenDeux] = $this->joueurDevantLaPorte($client);

        $instanceUn = (int)$this->entrerDansLeDonjon($client, $tokenUn)['instanceId'];
        $instanceDeux = (int)$this->entrerDansLeDonjon($client, $tokenDeux)['instanceId'];

        $vieGlobaleAvant = (int)$this->sqlFetchOne('SELECT actual_life FROM boss WHERE id = 1');

        // Le joueur 1 amoche le boss dans SON instance.
        $this->sql('UPDATE user SET map_id = 11, case_abscisse = 11, case_ordonnee = 7 WHERE id = ?', [$un['id']]);
        $this->frapperLeBoss($client, $tokenUn);

        $vieUn = $this->sqlFetchOne('SELECT boss_current_life FROM donjon_instance WHERE id = ?', [$instanceUn]);
        $vieDeux = $this->sqlFetchOne('SELECT boss_current_life FROM donjon_instance WHERE id = ?', [$instanceDeux]);

        $this->assertNotNull($vieUn, "Le coup doit être encaissé par l'instance");
        $this->assertLessThan(
            (int)$this->sqlFetchOne('SELECT max_life FROM boss WHERE id = 1'),
            (int)$vieUn
        );
        $this->assertNull($vieDeux, "L'instance voisine ne doit pas être entamée");
        $this->assertSame(
            $vieGlobaleAvant,
            (int)$this->sqlFetchOne('SELECT actual_life FROM boss WHERE id = 1'),
            "La colonne globale boss.actual_life ne bouge plus en donjon"
        );
    }

    public function testLeNiveauMinimumInterditLEntree(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client, niveau: 1);

        $reponse = $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => self::CASE_PORTE,
            'targetMapId' => self::CARTE_ENTREE,
            'targetWrap' => self::WRAP_ENTREE,
        ], $token);

        $this->assertArrayHasKey('message', $reponse);
        $this->assertStringContainsString('niveau', $reponse['message']);
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_instance WHERE leader_id = ?', [$user['id']]),
            "Un refus ne doit créer aucune instance"
        );
    }

    /**
     * Régression du 25/07/2026 : les portes INTERNES étaient annoncées au front comme des
     * portes d'entrée, si bien que passer de la salle 1 à la salle 2 rouvrait la modale de
     * groupe et affichait « vous avez déjà fait ce donjon aujourd'hui ».
     */
    public function testLesPassagesInternesNeSontPasDesPortesDEntree(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueurDevantLaPorte($client);

        // Depuis le monde ouvert, la porte du donjon EST une porte d'entrée.
        $dehors = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => 6], $token);
        $this->assertArrayHasKey(
            (string)self::CASE_PORTE,
            $dehors['portesDonjon'],
            "Vue du monde ouvert, la porte du donjon doit ouvrir la modale d'entrée"
        );

        $this->entrerDansLeDonjon($client, $token);

        // Une fois dedans, plus aucun passage de la salle ne doit être une porte d'entrée :
        // ni celui vers la salle 2, ni celui qui ressort vers le monde.
        $dedans = $this->jsonRequest($client, '/api/map/cases/data', ['mapId' => self::CARTE_ENTREE], $token);
        $this->assertSame(
            [],
            $dedans['portesDonjon'],
            "Dans son propre donjon, les passages restent des wraps ordinaires"
        );
    }

    /** Circuler entre salles ne doit ni recréer une instance ni reposer un verrou. */
    public function testChangerDeSalleNeRecreeNiInstanceNiVerrou(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);
        $instanceId = (int)$this->entrerDansLeDonjon($client, $token)['instanceId'];

        // Salle 1 (carte 8) → salle 2 (carte 9), par la case 2702.
        $reponse = $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => 2702,
            'targetMapId' => 9,
            'targetWrap' => 3444,
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(9, (int)$reponse['mapId'], 'Le joueur doit bel et bien changer de salle');
        $this->assertSame($instanceId, (int)$reponse['instanceId'], "L'instance reste la même");
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_verrou WHERE user_id = ?', [$user['id']]),
            'Un seul verrou : circuler ne consomme rien'
        );
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne(
                'SELECT COUNT(DISTINCT instance_id) FROM donjon_instance_membre WHERE user_id = ?',
                [$user['id']]
            )
        );
    }

    public function testSortirDuDonjonRendLaCaseDuMondeOuvert(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLaPorte($client);

        $this->entrerDansLeDonjon($client, $token);
        $this->sortirDuDonjon($client, $token);

        $this->assertSame(
            6,
            (int)$this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$user['id']])
        );
        $this->assertNotNull(
            $this->sqlFetchOne('SELECT id FROM carte_carreau WHERE joueur_id = ?', [$user['id']]),
            "De retour dehors, l'occupation redevient portée par la case"
        );
    }

    /* ------------------------------------------------------------------ */

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

    /** Replace le joueur sur la case voisine de la porte du donjon (carte 6). */
    private function devantLaPorte(int $userId): void
    {
        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$userId]);
        $this->sql('UPDATE user SET map_id = 6, case_abscisse = 10, case_ordonnee = 2 WHERE id = ?', [$userId]);
    }

    private function entrerDansLeDonjon(KernelBrowser $client, string $token): array
    {
        return $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => self::CASE_PORTE,
            'targetMapId' => self::CARTE_ENTREE,
            'targetWrap' => self::WRAP_ENTREE,
        ], $token);
    }

    private function sortirDuDonjon(KernelBrowser $client, string $token): array
    {
        // Case 3062 de la carte 8 = porte de sortie vers la carte 6.
        return $this->jsonRequest($client, '/api/joueur/map/update_position', [
            'wrapId' => 3062,
            'targetMapId' => 6,
            'targetWrap' => self::CASE_PORTE,
        ], $token);
    }

    private function frapperLeBoss(KernelBrowser $client, string $token): array
    {
        $spellId = (int)$this->sqlFetchOne("SELECT id FROM sortilege WHERE type = 'attack' LIMIT 1");

        return $this->jsonRequest($client, '/api/joueur/attack/boss', [
            'targetId' => 1,
            'spellId' => $spellId,
        ], $token);
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
        $unique = uniqid('donjon', true);
        $user = [
            'pseudo' => 'Donjon' . substr(md5($unique), 0, 10),
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
        return static::getContainer()->get('doctrine')->getConnection()->fetchOne($statement, $params) ?: null;
    }
}
