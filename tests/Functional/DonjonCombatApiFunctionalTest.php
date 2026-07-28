<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Mécaniques de combat du donjon, sur de vraies cases (chusei_test).
 *
 * Contenu seedé utilisé : donjon 1, boss 1 « Grimbald » posé carte 11 en (11,8),
 * mécaniques = zone télégraphiée (rayon 1, 180 dégâts, 12 s), renforts à 75-40 %,
 * énigme à 2 leviers (cases (7,8) et (15,8) de la carte 11), enrage à 25 % après 600 s.
 */
class DonjonCombatApiFunctionalTest extends WebTestCase
{
    private const CASE_PORTE = 1979;
    private const CARTE_ENTREE = 8;
    private const CARTE_BOSS = 11;
    private const BOSS_ID = 1;

    public function testUneAttaqueHorsDePorteeEstRefuseeParLeServeur(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDansLeDonjon($client);

        // Le boss est en (11,8) ; on se place à l'autre bout de la salle.
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = 0, case_ordonnee = 0 WHERE id = ?',
            [self::CARTE_BOSS, $user['id']]);
        $vieAvant = $this->vieBoss($user['id']);

        $reponse = $this->frapperLeBoss($client, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('hors de portée', $reponse['error']);
        $this->assertSame($vieAvant, $this->vieBoss($user['id']), "Un coup refusé ne doit pas entamer le boss");
    }

    /**
     * Une zone qui met les PV à 0 doit TUER : cimetière, PV rendus, sortie de l'instance.
     * Ce n'était traité nulle part — le joueur restait en vie NÉGATIVE sur la carte du
     * donjon, libre de se déplacer et toujours ciblé par le boss (le « zombie » du 27/07).
     */
    public function testUneZoneQuiViseAZeroTueVraimentLeJoueur(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $instanceId = $this->instanceDe($user['id']);

        // Combat engagé, joueur fragile, et une zone DÉJÀ échue centrée sur lui.
        $this->sql('UPDATE donjon_instance SET combat_debut_at = NOW(), boss_current_life = 3000 WHERE id = ?', [$instanceId]);
        $this->sql('UPDATE user SET current_life = 50 WHERE id = ?', [$user['id']]);
        $this->sql(
            "INSERT INTO donjon_instance_zone (instance_id, carte_id, cases, degats, annoncee_at, resoudre_at, resolue, annonce)
             VALUES (?, ?, '[{\"abscisse\": 11, \"ordonnee\": 7}]', 180, NOW() - INTERVAL 13 SECOND, NOW() - INTERVAL 1 SECOND, 0, 'Le sol se fissure')",
            [$instanceId, self::CARTE_BOSS]
        );

        $etat = $this->jsonRequest($client, '/api/donjon/combat', [], $token);

        $this->assertNotEmpty($etat['messages'], 'La résolution de la zone doit être annoncée');
        $this->assertStringContainsString('cimetière', implode(' ', $etat['messages']));

        $joueur = $this->sqlFetchAssoc('SELECT current_life, max_life, map_id FROM user WHERE id = ?', [$user['id']]);
        $this->assertGreaterThan(0, (int)$joueur['current_life'], 'Un mort ne reste pas en vie négative');
        $this->assertSame((int)$joueur['max_life'], (int)$joueur['current_life'], 'La mort rend les PV');
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT is_cimetiere FROM carte WHERE id = ?', [$joueur['map_id']]),
            'Le mort est renvoyé au cimetière, pas laissé dans le donjon'
        );
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne(
                'SELECT present FROM donjon_instance_membre WHERE instance_id = ? AND user_id = ?',
                [$instanceId, $user['id']]
            ),
            "Mourir fait quitter l'instance : le boss ne doit plus le cibler"
        );
    }

    public function testUneAttaqueSansPointsDActionEstRefusee(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $this->sql('UPDATE user SET action_point = 0 WHERE id = ?', [$user['id']]);

        $reponse = $this->frapperLeBoss($client, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("points d'action", $reponse['error']);
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT action_point FROM user WHERE id = ?', [$user['id']]),
            'Les PA ne doivent jamais passer en négatif'
        );
    }

    public function testFrapperLeBossAlimenteLaMenaceEtEngageLeCombat(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);

        $this->frapperLeBoss($client, $token);
        $this->assertResponseIsSuccessful();

        $instanceId = $this->instanceDe($user['id']);
        $this->assertGreaterThan(
            0,
            (int)$this->sqlFetchOne(
                'SELECT menace FROM donjon_instance_membre WHERE instance_id = ? AND user_id = ?',
                [$instanceId, $user['id']]
            ),
            'Les dégâts doivent alimenter la table de menace'
        );
        $this->assertNotNull(
            $this->sqlFetchOne('SELECT combat_debut_at FROM donjon_instance WHERE id = ?', [$instanceId]),
            "Le premier coup doit démarrer le chronomètre (origine de l'enrage)"
        );
    }

    /** La règle qui fait exister le tank : le boss ignore le dernier attaquant. */
    public function testLeBossFrappeLaPlusGrosseMenacePasLeDernierAttaquant(): void
    {
        $client = static::createClient();
        [$meneur, $tokenMeneur, $compagnon, $tokenCompagnon] = $this->groupeDansLaSalleDuBoss($client);
        $instanceId = $this->instanceDe($meneur['id']);

        // Le compagnon a une menace écrasante : c'est lui que le boss doit viser,
        // même quand c'est le meneur qui frappe.
        $this->sql('UPDATE donjon_instance_membre SET menace = 100000 WHERE instance_id = ? AND user_id = ?',
            [$instanceId, $compagnon['id']]);
        $this->sql('UPDATE user SET current_life = max_life WHERE id IN (?, ?)', [$meneur['id'], $compagnon['id']]);

        $vieMeneurAvant = (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$meneur['id']]);
        $vieCompagnonAvant = (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$compagnon['id']]);

        $this->frapperLeBoss($client, $tokenMeneur);
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            $vieMeneurAvant,
            (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$meneur['id']]),
            "L'attaquant ne doit PAS être frappé : il n'a pas la plus grosse menace"
        );
        $this->assertLessThan(
            $vieCompagnonAvant,
            (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$compagnon['id']]),
            'Le porteur de menace encaisse à la place'
        );
    }

    public function testLaZoneTelegraphieeEstAnnonceeAvantDeFrapper(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);

        $this->frapperLeBoss($client, $token);
        $instanceId = $this->instanceDe($user['id']);

        $zone = $this->sqlFetchAssoc(
            'SELECT cases, degats, resolue, resoudre_at FROM donjon_instance_zone WHERE instance_id = ?',
            [$instanceId]
        );

        $this->assertNotNull($zone, 'La mécanique de zone doit être annoncée au premier tick');
        $this->assertSame(0, (int)$zone['resolue'], 'La zone ne frappe pas immédiatement : le délai est le jeu');
        $this->assertGreaterThan(0, (int)$zone['degats']);

        // Elle est centrée sur la cible du boss : le joueur est dedans s'il ne bouge pas.
        $cases = json_decode($zone['cases'], true);
        $position = $this->sqlFetchAssoc('SELECT case_abscisse, case_ordonnee FROM user WHERE id = ?', [$user['id']]);
        $couvre = false;
        foreach ($cases as $case) {
            if ((int)$case['abscisse'] === (int)$position['case_abscisse']
                && (int)$case['ordonnee'] === (int)$position['case_ordonnee']) {
                $couvre = true;
            }
        }
        $this->assertTrue($couvre, 'La zone doit viser la position de la cible au moment de son annonce');
    }

    public function testSortirDeLaZoneAvantSaResolutionEviteLesDegats(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $this->frapperLeBoss($client, $token);
        $instanceId = $this->instanceDe($user['id']);

        // La zone devient échue, mais le joueur s'est écarté entre-temps.
        $this->sql('UPDATE donjon_instance_zone SET resoudre_at = NOW() - INTERVAL 1 SECOND WHERE instance_id = ?', [$instanceId]);
        $this->sql('UPDATE user SET case_abscisse = 0, case_ordonnee = 0, current_life = max_life WHERE id = ?', [$user['id']]);
        $vieAvant = (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$user['id']]);

        $this->jsonRequest($client, '/api/donjon/combat', [], $token);

        $this->assertSame(
            $vieAvant,
            (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$user['id']]),
            "Hors de la zone au moment où elle frappe : aucun dégât — c'est tout l'intérêt du délai"
        );
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT resolue FROM donjon_instance_zone WHERE instance_id = ?', [$instanceId])
        );
    }

    public function testResterDansLaZoneCoute(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $this->frapperLeBoss($client, $token);
        $instanceId = $this->instanceDe($user['id']);

        $this->sql('UPDATE donjon_instance_zone SET resoudre_at = NOW() - INTERVAL 1 SECOND WHERE instance_id = ?', [$instanceId]);
        $this->sql('UPDATE user SET current_life = max_life WHERE id = ?', [$user['id']]);
        $vieAvant = (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$user['id']]);

        $this->jsonRequest($client, '/api/donjon/combat', [], $token);

        $this->assertLessThan(
            $vieAvant,
            (int)$this->sqlFetchOne('SELECT current_life FROM user WHERE id = ?', [$user['id']]),
            'Immobile dans la zone annoncée : le joueur encaisse'
        );
    }

    public function testLesRenfortsApparaissentDansLaPhaseEtSontPropresALInstance(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $instanceId = $this->instanceDe($user['id']);

        // On amène le boss dans la fenêtre 75-40 % (mécanique « adds »).
        $this->sql('UPDATE donjon_instance SET boss_current_life = 3000, combat_debut_at = NOW() WHERE id = ?', [$instanceId]);

        $etat = $this->jsonRequest($client, '/api/donjon/combat', [], $token);

        $this->assertNotEmpty($etat['renforts'], 'La phase 75-40 % doit invoquer des renforts');
        $this->assertSame(
            count($etat['renforts']),
            (int)$this->sqlFetchOne(
                'SELECT COUNT(*) FROM donjon_instance_monstre WHERE instance_id = ? AND vivant = 1',
                [$instanceId]
            ),
            "Les renforts appartiennent à l'instance, pas au décor partagé"
        );
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM monstre_carreau mc JOIN carte_carreau cc ON cc.id = mc.carte_carreau_id WHERE cc.carte_id = ?', [self::CARTE_BOSS]),
            "Aucun renfort ne doit être écrit dans monstre_carreau (table du décor)"
        );
    }

    public function testUnRenfortSeCombatEtMeurt(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $instanceId = $this->instanceDe($user['id']);
        $this->sql('UPDATE donjon_instance SET boss_current_life = 3000, combat_debut_at = NOW() WHERE id = ?', [$instanceId]);

        $etat = $this->jsonRequest($client, '/api/donjon/combat', [], $token);
        $renfort = $etat['renforts'][0];

        // On se colle au renfort et on le met à un souffle de la mort.
        $this->sql('UPDATE user SET case_abscisse = ?, case_ordonnee = ?, current_life = max_life, action_point = 100 WHERE id = ?',
            [$renfort['abscisse'], $renfort['ordonnee'] + 1, $user['id']]);
        $this->sql('UPDATE donjon_instance_monstre SET current_life = 1 WHERE id = ?', [$renfort['id']]);

        $reponse = $this->jsonRequest($client, '/api/donjon/renfort/attaquer', [
            'renfortId' => $renfort['id'],
            'spellId' => $this->sortDAttaque(),
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($reponse['mort']);
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT vivant FROM donjon_instance_monstre WHERE id = ?', [$renfort['id']])
        );
    }

    public function testUnLevierSeulNeResoutPasLEnigme(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurDevantLeBoss($client);
        $instanceId = $this->instanceDe($user['id']);
        $this->sql('UPDATE donjon_instance SET boss_current_life = 5000 WHERE id = ?', [$instanceId]);

        $reponse = $this->actionnerLevier($client, $token, $user['id'], 7, 8);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('résiste', $reponse['messages'][0]);
        $this->assertSame(
            5000,
            (int)$this->sqlFetchOne('SELECT boss_current_life FROM donjon_instance WHERE id = ?', [$instanceId])
        );
    }

    /** L'énigme exige des joueurs DIFFÉRENTS : un seul joueur ne peut pas la résoudre. */
    public function testDeuxLeviersParDeuxJoueursResolventLEnigmeEtBlessentLeBoss(): void
    {
        $client = static::createClient();
        [$meneur, $tokenMeneur, $compagnon, $tokenCompagnon] = $this->groupeDansLaSalleDuBoss($client);
        $instanceId = $this->instanceDe($meneur['id']);
        $this->sql('UPDATE donjon_instance SET boss_current_life = 5000 WHERE id = ?', [$instanceId]);

        $this->actionnerLevier($client, $tokenMeneur, $meneur['id'], 7, 8);
        $reponse = $this->actionnerLevier($client, $tokenCompagnon, $compagnon['id'], 15, 8);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('chaînes', $reponse['messages'][0]);
        $this->assertSame(
            4400, // 5000 - 600 (degatsBoss de la mécanique seedée)
            (int)$this->sqlFetchOne('SELECT boss_current_life FROM donjon_instance WHERE id = ?', [$instanceId]),
            "L'énigme résolue doit blesser le boss"
        );
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM donjon_instance_levier WHERE instance_id = ?', [$instanceId]),
            'Les leviers se réarment après résolution'
        );
    }

    public function testUnLevierHorsDonjonEstRefuse(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = 7, case_ordonnee = 9 WHERE id = ?',
            [self::CARTE_BOSS, $user['id']]);

        $reponse = $this->jsonRequest($client, '/api/map/action',
            ['actionId' => $this->actionDuLevier(7, 8)], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("à l'intérieur du donjon", $reponse['error']);
    }

    /* ------------------------------------------------------------------ */

    private function actionnerLevier(KernelBrowser $client, string $token, int $userId, int $abscisse, int $ordonnee): array
    {
        // Le joueur doit être adjacent au levier (contrôle de QuestProgressionService).
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = ?, case_ordonnee = ? WHERE id = ?',
            [self::CARTE_BOSS, $abscisse, $ordonnee + 1, $userId]);

        return $this->jsonRequest($client, '/api/map/action',
            ['actionId' => $this->actionDuLevier($abscisse, $ordonnee)], $token);
    }

    private function actionDuLevier(int $abscisse, int $ordonnee): int
    {
        return (int)$this->sqlFetchOne(
            'SELECT action_id FROM carte_carreau WHERE carte_id = ? AND abscisse = ? AND ordonnee = ?',
            [self::CARTE_BOSS, $abscisse, $ordonnee]
        );
    }

    private function frapperLeBoss(KernelBrowser $client, string $token): array
    {
        return $this->jsonRequest($client, '/api/joueur/attack/boss', [
            'targetId' => self::BOSS_ID,
            'spellId' => $this->sortDAttaque(),
        ], $token);
    }

    private function sortDAttaque(): int
    {
        return (int)$this->sqlFetchOne("SELECT id FROM sortilege WHERE type = 'attack' ORDER BY portee DESC LIMIT 1");
    }

    private function vieBoss(int $userId): ?int
    {
        $vie = $this->sqlFetchOne(
            'SELECT boss_current_life FROM donjon_instance WHERE id = ?',
            [$this->instanceDe($userId)]
        );

        return $vie === null ? null : (int)$vie;
    }

    private function instanceDe(int $userId): int
    {
        return (int)$this->sqlFetchOne(
            'SELECT instance_id FROM donjon_instance_membre WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /** @return array{0: array, 1: string} joueur entré en solo, posé devant le boss */
    private function joueurDevantLeBoss(KernelBrowser $client): array
    {
        [$user, $token] = $this->joueurDansLeDonjon($client);
        // Grimbald frappe pour ~440 : un personnage de base (400 PV) meurt au premier coup,
        // part au cimetière et QUITTE l'instance — plus rien à observer. On lui donne de
        // quoi encaisser, c'est le rôle du groupe de 5 dans la vraie partie.
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = 11, case_ordonnee = 7,
                    max_life = 20000, current_life = 20000, action_point = 200 WHERE id = ?',
            [self::CARTE_BOSS, $user['id']]);

        return [$user, $token];
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
            'wrapId' => self::CASE_PORTE,
            'targetMapId' => self::CARTE_ENTREE,
            'targetWrap' => 3062,
        ], $token);

        return [$user, $token];
    }

    /** @return array{0: array, 1: string, 2: array, 3: string} */
    private function groupeDansLaSalleDuBoss(KernelBrowser $client): array
    {
        [$meneur, $tokenMeneur] = $this->preparerJoueur($client);
        [$compagnon, $tokenCompagnon] = $this->preparerJoueur($client);

        $groupe = $this->jsonRequest($client, '/api/donjon/groupe/creer', ['carteCarreauId' => self::CASE_PORTE], $tokenMeneur);
        $this->jsonRequest($client, '/api/donjon/groupe/rejoindre', ['groupeId' => $groupe['groupe']['id']], $tokenCompagnon);
        $this->jsonRequest($client, '/api/donjon/groupe/lancer', [], $tokenMeneur);

        foreach ([[$meneur['id'], 11], [$compagnon['id'], 12]] as [$id, $abscisse]) {
            $this->sql('UPDATE user SET map_id = ?, case_abscisse = ?, case_ordonnee = 7,
                        max_life = 20000, current_life = 20000, action_point = 200 WHERE id = ?',
                [self::CARTE_BOSS, $abscisse, $id]);
        }

        return [$meneur, $tokenMeneur, $compagnon, $tokenCompagnon];
    }

    /** @return array{0: array, 1: string} */
    private function preparerJoueur(KernelBrowser $client): array
    {
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);
        $this->sql('UPDATE niveau_joueur SET niveau_id = 20 WHERE user_id = ?', [$user['id']]);
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
        $unique = uniqid('combat', true);
        $user = [
            'pseudo' => 'Combat' . substr(md5($unique), 0, 10),
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

    private function sqlFetchAssoc(string $statement, array $params = []): ?array
    {
        $row = static::getContainer()->get('doctrine')->getConnection()->fetchAssociative($statement, $params);

        return $row === false ? null : $row;
    }
}
