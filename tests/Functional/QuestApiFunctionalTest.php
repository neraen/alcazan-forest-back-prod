<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours de quête de bout en bout sur chusei_test (schéma : migrations,
 * contenu : seeds/content-seed.sql). S'appuie sur le contenu seedé :
 * PNJ 1 = Leopold (quête 1 « Choix de la classe », 2 séquences),
 * PNJ 4 = Aubergiste (séquence 3 sans quête, effet entrer_auberge),
 * action 7 = récompense de boss posée sur la case (11,4) de la carte 15.
 */
class QuestApiFunctionalTest extends WebTestCase
{
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
        $unique = uniqid('quest', true);
        $user = [
            'pseudo' => 'Quest' . substr(md5($unique), 0, 10),
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
        return static::getContainer()->get('doctrine')->getConnection()->fetchOne($statement, $params);
    }

    public function testLInteractionPnjEstUneLecturePure(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $interaction = $this->jsonRequest($client, '/api/pnj/interaction', ['pnjId' => 1], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('quest', $interaction['view']);
        $this->assertSame('available', $interaction['quest']['status']);
        $this->assertSame('Choix de la classe', $interaction['quest']['name']);

        // Régression majeure : l'ancien POST /api/pnj créait le user_quete à la consultation.
        $count = $this->sqlFetchOne('SELECT COUNT(*) FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(0, (int)$count, "Consulter un PNJ ne doit PAS démarrer la quête");
    }

    public function testParcoursCompletDeLaQueteInitiale(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        /* Démarrage explicite */
        $step = $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame('step', $step['status']);
        $this->assertCount(4, $step['step']['actions'], 'La séquence 1 propose les 4 classes');
        $this->assertNotEmpty($step['step']['dialogue']['paragraphs']);

        /* Choix de la classe (effet scripté) */
        $archer = array_values(array_filter($step['step']['actions'], fn ($a) => $a['label'] === 'Devenir Archer'))[0];
        $this->assertSame('SCRIPTED_EFFECT', $archer['type']);

        $next = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => $step['step']['sequenceId'],
            'actionId' => $archer['actionId'],
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('step', $next['status'], 'Le choix de classe mène au dialogue de fin');
        $this->assertTrue($next['needRefresh']);
        $rewardTypes = array_column($next['feedback']['rewards'], 'type');
        $this->assertContains('or', $rewardTypes);
        $this->assertContains('experience', $rewardTypes);
        $this->assertContains('equipement', $rewardTypes);

        $classeId = $this->sqlFetchOne('SELECT classe_id FROM user WHERE id = ?', [$user['id']]);
        $classeNom = $this->sqlFetchOne('SELECT nom FROM classe WHERE id = ?', [$classeId]);
        $this->assertSame('archer', $classeNom);

        /* Dernière séquence : « Terminer » clôt la quête */
        $this->assertCount(1, $next['step']['actions']);
        $done = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => $next['step']['sequenceId'],
            'actionId' => $next['step']['actions'][0]['actionId'],
        ], $token);

        $this->assertSame('done', $done['status']);
        $isDone = $this->sqlFetchOne('SELECT is_done FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(1, (int)$isDone);

        /* L'interaction reflète l'état terminé */
        $interaction = $this->jsonRequest($client, '/api/pnj/interaction', ['pnjId' => 1], $token);
        $this->assertSame('done', $interaction['quest']['status']);
    }

    public function testDemarrerDeuxFoisNeCreePasDeDoublon(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);
        $second = $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('step', $second['status'], 'Re-démarrer renvoie l\'étape courante');
        $count = $this->sqlFetchOne('SELECT COUNT(*) FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(1, (int)$count);
    }

    public function testUneActionSurUneAutreEtapeQueLaCouranteEchoue(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);

        // Le joueur est à l'étape 1 (séquence 1) : jouer « Terminer » (séquence 2) doit échouer.
        $terminerActionId = $this->sqlFetchOne(
            "SELECT sa.action_id FROM sequence_action sa JOIN sequence s ON s.id = sa.sequence_id WHERE s.quete_id = 1 AND s.position = 2"
        );
        $response = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => 2,
            'actionId' => (int)$terminerActionId,
        ], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', $response);
    }

    public function testLeDialogueAubergeExigeLaProximiteDuPnj(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Le joueur spawn carte 2 : l'aubergiste (PNJ 4) est carte 4 en (14,5).
        $response = $this->jsonRequest($client, '/api/quest/action', ['sequenceId' => 3, 'actionId' => 5], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('trop loin', $response['error']);
    }

    public function testLAubergisteTeleporteLeJoueurAdjacent(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Téléporte le joueur à côté de l'aubergiste (carte 4, PNJ en (14,5)).
        $this->sql('UPDATE user SET map_id = 4, case_abscisse = 13, case_ordonnee = 5 WHERE id = ?', [$user['id']]);

        $response = $this->jsonRequest($client, '/api/quest/action', ['sequenceId' => 3, 'actionId' => 5], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('done', $response['status']);
        $this->assertTrue($response['needRefresh']);
        $this->assertStringContainsString('auberge', $response['feedback']['messages'][0]['text']);

        $mapId = $this->sqlFetchOne('SELECT map_id FROM user WHERE id = ?', [$user['id']]);
        $this->assertSame(14, (int)$mapId, 'Le joueur doit être téléporté dans l\'auberge (carte 14)');
    }

    public function testLActionDeCaseExigeLaProximite(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // L'action 7 est posée carte 15 en (11,4) ; le joueur spawn carte 2.
        $response = $this->jsonRequest($client, '/api/map/action', ['actionId' => 7], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('trop loin', $response['error']);
    }

    public function testLeCoffreDuBossExigeUneMiseAMortRecente(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE user SET map_id = 15, case_abscisse = 11, case_ordonnee = 5 WHERE id = ?', [$user['id']]);

        $response = $this->jsonRequest($client, '/api/map/action', ['actionId' => 7], $token);

        $this->assertResponseStatusCodeSame(400, "Sans kill, le coffre ne doit rien donner");
        $this->assertStringContainsString('vide', $response['error']);
    }

    public function testLeCoffreDuBossDistribueLeButinUneSeuleFoisParKill(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE user SET map_id = 15, case_abscisse = 11, case_ordonnee = 5 WHERE id = ?', [$user['id']]);
        $this->sql(
            'INSERT INTO user_boss (user_id, boss_id, last_kill, number_kill) VALUES (?, 1, NOW(), 1)',
            [$user['id']]
        );
        $orAvant = (int)$this->sqlFetchOne('SELECT money FROM user WHERE id = ?', [$user['id']]);

        $response = $this->jsonRequest($client, '/api/map/action', ['actionId' => 7], $token);

        $this->assertResponseIsSuccessful();
        $this->assertNotEmpty($response['messages']);

        // Le butin (table 70/30 de Grimbald : or + XP dans les deux cas) est réellement crédité.
        $orApres = (int)$this->sqlFetchOne('SELECT money FROM user WHERE id = ?', [$user['id']]);
        $this->assertGreaterThan($orAvant, $orApres, "L'or du butin doit être crédité, pas seulement annoncé");

        $lastLoot = $this->sqlFetchOne('SELECT last_loot FROM user_boss WHERE user_id = ?', [$user['id']]);
        $this->assertNotNull($lastLoot, 'Le ramassage doit être horodaté');

        // Second clic sur la même case : le coffre est vide jusqu'au prochain kill.
        $rejeu = $this->jsonRequest($client, '/api/map/action', ['actionId' => 7], $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('vide', $rejeu['error']);
        $this->assertSame(
            $orApres,
            (int)$this->sqlFetchOne('SELECT money FROM user WHERE id = ?', [$user['id']]),
            "Un second passage ne doit pas re-créditer d'or"
        );
    }

    public function testLeQuestMakerEstReserveAuxAdmins(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->jsonRequest($client, '/api/quest/editor/list', [], $token);
        $this->assertResponseStatusCodeSame(403, 'Même les lectures du QuestMaker sont réservées aux admins');
    }

    public function testLaSauvegardeEditeurEstIdempotenteEtSansChurnDIds(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        $quest = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $this->assertResponseIsSuccessful();
        $originalSequenceIds = array_column($quest['sequences'], 'id');
        $originalActionIds = array_merge(...array_map(fn ($s) => array_column($s['actions'], 'id'), $quest['sequences']));

        /* Re-sauvegarde du payload tel quel : aucun id ne doit changer
           (l'ancien updateQuest supprimait et recréait toutes les actions). */
        $saved = $this->jsonRequest($client, '/api/quest/editor/save', $quest, $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame($originalSequenceIds, array_column($saved['sequences'], 'id'));
        $this->assertSame(
            $originalActionIds,
            array_merge(...array_map(fn ($s) => array_column($s['actions'], 'id'), $saved['sequences']))
        );

        /* Modification d'un libellé : toujours les mêmes ids */
        $saved['sequences'][0]['actions'][0]['label'] = 'Devenir Archer (test)';
        $saved2 = $this->jsonRequest($client, '/api/quest/editor/save', $saved, $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Devenir Archer (test)', $saved2['sequences'][0]['actions'][0]['label']);
        $this->assertSame($originalActionIds, array_merge(...array_map(fn ($s) => array_column($s['actions'], 'id'), $saved2['sequences'])));

        /* Remise en état pour les autres tests */
        $saved2['sequences'][0]['actions'][0]['label'] = 'Devenir Archer';
        $this->jsonRequest($client, '/api/quest/editor/save', $saved2, $token);
        $this->assertResponseIsSuccessful();
    }

    public function testLeBranchementDUneActionEstPersisteEtRelu(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        $original = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $this->assertGreaterThanOrEqual(2, count($original['sequences']), 'La quête 1 doit avoir au moins 2 séquences.');

        // Sauvegarde d'une copie de travail : la 1re action branche vers la 2e
        // séquence (via son clientKey), la 2e action termine la quête.
        $quest = $original;
        $targetKey = $quest['sequences'][1]['clientKey'];
        $quest['sequences'][0]['actions'][0]['nextSequenceKey'] = $targetKey;
        $quest['sequences'][0]['actions'][1]['nextSequenceKey'] = '__END__';

        $this->jsonRequest($client, '/api/quest/editor/save', $quest, $token);
        $this->assertResponseIsSuccessful();

        // Relecture : les branchements sont persistés et resérialisés.
        $reloaded = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $secondSequenceId = (string)$reloaded['sequences'][1]['id'];
        $this->assertSame($secondSequenceId, $reloaded['sequences'][0]['actions'][0]['nextSequenceKey']);
        $this->assertSame('__END__', $reloaded['sequences'][0]['actions'][1]['nextSequenceKey']);
        // Les autres choix restent en linéaire par défaut.
        $this->assertSame('', $reloaded['sequences'][0]['actions'][2]['nextSequenceKey']);

        // Remise en état pour les autres tests (rebranchement linéaire + fin).
        $this->jsonRequest($client, '/api/quest/editor/save', $original, $token);
        $this->assertResponseIsSuccessful();
        $restored = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $this->assertSame('', $restored['sequences'][0]['actions'][0]['nextSequenceKey']);
    }

    public function testLaSauvegardeEditeurRefuseUneSequenceSansAction(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        $quest = $this->jsonRequest($client, '/api/quest/editor/get', ['questId' => 1], $token);
        $quest['sequences'][0]['actions'] = [];

        $response = $this->jsonRequest($client, '/api/quest/editor/save', $quest, $token);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('au moins une action', $response['error']);
    }

    /* ---------------------------------------------------------------- */
    /* Objectifs comptés et karma                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Une quête de chasse, écrite par l'éditeur puis jouée : elle doit bloquer tant que
     * les monstres ne sont pas tombés, et se débloquer sur les kills faits APRÈS la
     * demande — pas sur ceux que le joueur avait déjà à son actif.
     *
     * L'antériorité est simulée en base (`joueur_compteur`) plutôt qu'en tuant vraiment :
     * ce qu'on vérifie ici est justement que ces kills-là ne comptent pas.
     */
    public function testUneQueteDeChasseNeCompteQueLesKillsPosterieursALaDemande(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        // 10 loups (monstre 4) déjà tués AVANT que la quête n'existe.
        $this->sql(
            'INSERT INTO joueur_compteur (user_id, type, cible_id, valeur, maj_at) VALUES (?, ?, 4, 10, NOW())',
            [$user['id'], 'monstre_tue']
        );

        // PNJ jetable : hijacker le PNJ 1 (Leopold) casserait les autres tests du
        // fichier, qui s'appuient sur sa quête seedée.
        $pnjId = $this->createThrowawayPnj();
        $questId = 0;

        try {
            $questId = $this->createHuntQuest($client, $token, $pnjId);

            $start = $this->jsonRequest($client, '/api/quest/start', ['pnjId' => $pnjId], $token);
            $this->assertResponseIsSuccessful();
            $this->assertSame('step', $start['status']);

            $chasse = $start['step']['actions'][0];
            $this->assertSame(
                0,
                $chasse['progress']['current'],
                'Les 10 loups antérieurs ne doivent pas compter : la quête repart de zéro'
            );
            $this->assertSame(2, $chasse['progress']['target']);

            // Une tentative immédiate est bloquée, avec le compte réel.
            $bloque = $this->jsonRequest($client, '/api/quest/action', [
                'sequenceId' => $start['step']['sequenceId'],
                'actionId' => $chasse['actionId'],
            ], $token);
            $this->assertSame('blocked', $bloque['status']);
            $this->assertStringContainsString('0 / 2', $bloque['blockedMessages'][0]);

            // Deux loups tombent après la demande.
            $this->sql(
                'UPDATE joueur_compteur SET valeur = 12 WHERE user_id = ? AND type = ? AND cible_id = 4',
                [$user['id'], 'monstre_tue']
            );

            $fini = $this->jsonRequest($client, '/api/quest/action', [
                'sequenceId' => $start['step']['sequenceId'],
                'actionId' => $chasse['actionId'],
            ], $token);

            $this->assertResponseIsSuccessful();
            $this->assertSame('done', $fini['status'], 'Deux loups depuis la demande suffisent');

            // Le karma du choix a été appliqué et borné par KarmaService.
            $this->assertSame(15, $fini['karma']['delta']);
            $this->assertSame(15, (int)$this->sqlFetchOne('SELECT karma FROM user WHERE id = ?', [$user['id']]));
        } finally {
            // Nettoyage même en cas d'échec : une quête de test laissée en base ferait
            // échouer les suivantes de façon incompréhensible.
            if ($questId > 0) {
                $this->jsonRequest($client, '/api/quest/editor/delete', ['questId' => $questId], $token);
            }
            $this->sql('DELETE FROM pnj WHERE id = ?', [$pnjId]);
        }
    }

    /** Tuer un monstre en jeu alimente réellement `joueur_compteur`. */
    public function testTuerUnMonstreAlimenteLeCompteur(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        // Une population de monstre 4 sur une case, et le joueur à côté, avec assez de
        // vie et de PA pour que le coup parte.
        $carreau = (int)$this->sqlFetchOne('SELECT id FROM carte_carreau WHERE carte_id = 15 LIMIT 1');
        // `quantity_base` (population de référence de la case) n'a pas de valeur par
        // défaut en base : l'omettre fait échouer l'INSERT en mode strict.
        $this->sql(
            'INSERT INTO monstre_carreau (monstre_id, carte_carreau_id, quantity, quantity_base, current_life) VALUES (4, ?, 5, 5, 1)',
            [$carreau]
        );
        $cibleId = (int)$this->sqlFetchOne('SELECT LAST_INSERT_ID()');

        $avant = (int)$this->sqlFetchOne(
            'SELECT COALESCE(valeur, 0) FROM joueur_compteur WHERE user_id = ? AND type = ? AND cible_id = 4',
            [$user['id'], 'monstre_tue']
        );

        $this->killMonstre($client, $token, $user, $cibleId);

        $apres = (int)$this->sqlFetchOne(
            'SELECT COALESCE(valeur, 0) FROM joueur_compteur WHERE user_id = ? AND type = ? AND cible_id = 4',
            [$user['id'], 'monstre_tue']
        );

        $this->assertSame($avant + 1, $apres, 'La mise à mort doit incrémenter le compteur du monstre');

        $this->sql('DELETE FROM monstre_carreau WHERE id = ?', [$cibleId]);
    }

    /**
     * Le compteur doit résister au parallélisme : c'est pour ça que le repository fait
     * un upsert atomique et non un read-modify-write, qui perdrait des mises à mort.
     */
    public function testLeCompteurNePerdPasDIncrementSurUnUpsert(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->login($client, $user);

        $compteur = static::getContainer()->get(\App\service\CompteurJoueurService::class);
        $entity = static::getContainer()->get('doctrine')->getRepository(\App\Entity\User::class)->find($user['id']);

        $this->assertSame(1, $compteur->incrementer($entity, \App\Enum\TypeCompteur::MONSTRE_TUE, 4));
        $this->assertSame(4, $compteur->incrementer($entity, \App\Enum\TypeCompteur::MONSTRE_TUE, 4, 3));
        $this->assertSame(4, $compteur->valeur($entity, \App\Enum\TypeCompteur::MONSTRE_TUE, 4));

        // Une seule ligne : l'index unique (user, type, cible) fait son office.
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne(
                'SELECT COUNT(*) FROM joueur_compteur WHERE user_id = ? AND type = ? AND cible_id = 4',
                [$user['id'], 'monstre_tue']
            )
        );
    }

    /** PNJ minimal, propre à ce test, supprimé à la fin. */
    private function createThrowawayPnj(): int
    {
        $this->sql(
            "INSERT INTO pnj (name, avatar, skin, description, type) VALUES ('Veneur de test', 'default.png', 'default', 'Il chasse.', 'action')"
        );

        return (int)$this->sqlFetchOne('SELECT LAST_INSERT_ID()');
    }

    /**
     * Écrit une quête « tuer 2 loups » portée par `$pnjId`, avec un karma positif sur
     * l'action. Renvoie l'id de la quête créée.
     */
    private function createHuntQuest(KernelBrowser $client, string $token, int $pnjId): int
    {
        $saved = $this->jsonRequest($client, '/api/quest/editor/save', [
            'id' => 0,
            'name' => 'Chasse aux loups (test)',
            'introduction' => 'Les loups rôdent.',
            'minimalLevel' => 0,
            'alignementId' => 0,
            'objetId' => 0,
            'prerequisiteQueteId' => 0,
            'sequences' => [[
                'id' => 0,
                'clientKey' => 'new-hunt-1',
                'nomSequence' => 'La battue',
                'dialogueTitre' => 'La battue',
                'dialogueContenu' => 'Débarrasse-nous de ces bêtes.',
                'pnjId' => $pnjId,
                'actions' => [[
                    'id' => 0,
                    'type' => 'BATTRE_MONSTRE',
                    'label' => "C'est fait",
                    'message' => '',
                    'quantity' => 2,
                    'monstreId' => 4,
                    'karma' => 15,
                    'nextSequenceKey' => '__END__',
                    'recompense' => ['money' => 0, 'experience' => 0, 'quantity' => 0, 'objetId' => 0, 'equipementId' => 0, 'consommableId' => 0],
                ]],
            ]],
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame(15, $saved['sequences'][0]['actions'][0]['karma'], 'Le karma doit être relu tel qu\'enregistré');
        $this->assertSame(4, $saved['sequences'][0]['actions'][0]['monstreId']);

        return $saved['id'];
    }

    /** Frappe un monstre jusqu'à ce qu'il tombe (sa vie a été mise à 1). */
    private function killMonstre(KernelBrowser $client, string $token, array $user, int $monstreCarreauId): void
    {
        $carreau = $this->sqlFetchOne('SELECT carte_carreau_id FROM monstre_carreau WHERE id = ?', [$monstreCarreauId]);
        $position = static::getContainer()->get('doctrine')->getConnection()->fetchAssociative(
            'SELECT carte_id, abscisse, ordonnee FROM carte_carreau WHERE id = ?',
            [$carreau]
        );

        $this->sql(
            'UPDATE user SET map_id = ?, case_abscisse = ?, case_ordonnee = ?, action_point = 500, current_life = 5000 WHERE id = ?',
            [$position['carte_id'], $position['abscisse'], $position['ordonnee'], $user['id']]
        );

        $spellId = (int)$this->sqlFetchOne(
            'SELECT s.id FROM sortilege s JOIN user u ON u.classe_id = s.classe_id WHERE u.id = ? LIMIT 1',
            [$user['id']]
        );

        $this->jsonRequest($client, '/api/joueur/attack/monster', [
            'spellId' => $spellId,
            'targetId' => $monstreCarreauId,
        ], $token);
        $this->assertResponseIsSuccessful();
    }
}
