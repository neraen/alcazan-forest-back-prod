<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Journal de quêtes (`POST /api/quest/journal`) sur chusei_test.
 *
 * S'appuie sur le contenu seedé : PNJ 1 = Leopold, posé sur une carte, porteur de la
 * quête 1 « Choix de la classe » (séquence 1 = quatre choix de classe à la récompense
 * identique, séquence 2 = « Terminer »).
 *
 * Les assertions portent sur la quête 1 et sur des quêtes créées ici, jamais sur le
 * NOMBRE total de quêtes listées : la base de test n'est pas réinitialisée entre deux
 * exécutions et le contenu peut évoluer.
 */
class QuestJournalApiFunctionalTest extends WebTestCase
{
    public function testLeJournalEstUneLecturePureEtListeLaQueteInitialeDisponible(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $journal = $this->journal($client, $token);

        $this->assertSame([], $journal['enCours']);
        $this->assertSame([], $journal['terminees']);
        $initiale = $this->trouver($journal['disponibles'], 1);
        $this->assertNotNull($initiale, 'La quête initiale doit être proposée à un nouveau joueur');
        $this->assertSame('disponible', $initiale['statut']);
        $this->assertSame(1, $initiale['donneur']['id']);
        $this->assertNotNull($initiale['donneur']['lieu'], 'Une quête disponible dit OÙ trouver son donneur');

        $count = $this->sqlFetchOne('SELECT COUNT(*) FROM user_quete WHERE user_id = ?', [$user['id']]);
        $this->assertSame(0, (int)$count, 'Ouvrir le journal ne doit démarrer aucune quête');
    }

    public function testUneQueteSuitLeJoueurDeDisponibleAEnCoursPuisATerminee(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $start = $this->jsonRequest($client, '/api/quest/start', ['pnjId' => 1], $token);
        $this->assertSame('step', $start['status']);

        $journal = $this->journal($client, $token);
        $this->assertNull($this->trouver($journal['disponibles'], 1), 'Une quête commencée quitte les disponibles');
        $enCours = $this->trouver($journal['enCours'], 1);
        $this->assertNotNull($enCours);

        $etape = $enCours['etape'];
        $this->assertSame($start['step']['sequenceId'], $etape['sequenceId']);
        $this->assertSame(1, $etape['numero']);
        $this->assertSame(1, $etape['pnj']['id'], "L'étape dit quel PNJ aller voir");
        // Choisir sa classe ne demande rien d'autre que de parler : aucun objectif, prêt.
        $this->assertSame([], $etape['objectifs']);
        $this->assertTrue($etape['pret']);
        // Les quatre choix rapportent la même chose : la récompense est annoncée.
        $this->assertFalse($etape['recompensesVariables']);
        $this->assertContains('or', array_column($etape['recompenses'], 'type'));

        // Jouer l'étape puis « Terminer ».
        $next = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => $start['step']['sequenceId'],
            'actionId' => $start['step']['actions'][0]['actionId'],
        ], $token);
        $this->assertSame('step', $next['status']);

        $journal = $this->journal($client, $token);
        $this->assertSame(2, $this->trouver($journal['enCours'], 1)['etape']['numero']);

        $done = $this->jsonRequest($client, '/api/quest/action', [
            'sequenceId' => $next['step']['sequenceId'],
            'actionId' => $next['step']['actions'][0]['actionId'],
        ], $token);
        $this->assertSame('done', $done['status']);

        $journal = $this->journal($client, $token);
        $this->assertNull($this->trouver($journal['enCours'], 1));
        $terminee = $this->trouver($journal['terminees'], 1);
        $this->assertNotNull($terminee);
        $this->assertNotNull($terminee['termineeLe'], 'La date de fin vient du journal d\'événements');
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $terminee['termineeLe']));
    }

    public function testUnObjectifDeChasseEstChiffreEtUnDonneurHorsCarteNEstPasPropose(): void
    {
        $client = static::createClient();
        $user = $this->registerUser($client);
        $this->sql("UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [$user['id']]);
        $token = $this->login($client, $user);

        // Loups tués AVANT la demande : ils ne doivent pas compter.
        $this->sql(
            'INSERT INTO joueur_compteur (user_id, type, cible_id, valeur, maj_at) VALUES (?, ?, 4, 10, NOW())',
            [$user['id'], 'monstre_tue']
        );

        $this->sql(
            "INSERT INTO pnj (name, avatar, skin, description, type) VALUES ('Veneur du journal', 'default.png', 'default', 'Il chasse.', 'quest')"
        );
        $pnjId = (int)$this->sqlFetchOne('SELECT LAST_INSERT_ID()');
        $questId = 0;

        try {
            $questId = $this->createHuntQuest($client, $token, $pnjId);
            $this->sql('UPDATE pnj SET quete_id = ? WHERE id = ?', [$questId, $pnjId]);

            // PNJ posé sur aucune carte : la quête serait introuvable, on ne l'annonce pas.
            $journal = $this->journal($client, $token);
            $this->assertNull($this->trouver($journal['disponibles'], $questId));

            $this->jsonRequest($client, '/api/quest/start', ['pnjId' => $pnjId], $token);
            $this->assertResponseIsSuccessful();
            $this->sql(
                'UPDATE joueur_compteur SET valeur = 11 WHERE user_id = ? AND type = ? AND cible_id = 4',
                [$user['id'], 'monstre_tue']
            );

            $etape = $this->trouver($this->journal($client, $token)['enCours'], $questId)['etape'];
            $this->assertCount(1, $etape['objectifs']);
            $objectif = $etape['objectifs'][0];
            $this->assertSame('BATTRE_MONSTRE', $objectif['type']);
            $this->assertStringStartsWith('Vaincre 2 × ', $objectif['libelle']);
            $this->assertSame("C'est fait", $objectif['bouton']);
            $this->assertSame(['current' => 1, 'target' => 2], array_intersect_key($objectif['progress'], ['current' => 0, 'target' => 0]));
            $this->assertFalse($objectif['fait']);
            $this->assertFalse($etape['pret'], 'Un objectif non rempli : rien à rendre au PNJ');
            $this->assertNull($etape['pnj']['lieu'], 'Un PNJ hors carte se dit sans lieu, pas avec un faux');

            $this->sql(
                'UPDATE joueur_compteur SET valeur = 12 WHERE user_id = ? AND type = ? AND cible_id = 4',
                [$user['id'], 'monstre_tue']
            );
            $etape = $this->trouver($this->journal($client, $token)['enCours'], $questId)['etape'];
            $this->assertTrue($etape['objectifs'][0]['fait']);
            $this->assertTrue($etape['pret']);
        } finally {
            if ($questId > 0) {
                $this->sql('UPDATE pnj SET quete_id = NULL WHERE id = ?', [$pnjId]);
                $this->jsonRequest($client, '/api/quest/editor/delete', ['questId' => $questId], $token);
            }
            $this->sql('DELETE FROM pnj WHERE id = ?', [$pnjId]);
        }
    }

    public function testLeJournalExigeUneAuthentification(): void
    {
        $client = static::createClient();
        $this->jsonRequest($client, '/api/quest/journal', []);
        $this->assertResponseStatusCodeSame(401);
    }

    private function journal(KernelBrowser $client, string $token): array
    {
        $journal = $this->jsonRequest($client, '/api/quest/journal', [], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['enCours', 'disponibles', 'terminees'], array_keys($journal));

        return $journal;
    }

    private function trouver(array $fiches, int $questId): ?array
    {
        foreach ($fiches as $fiche) {
            if ($fiche['id'] === $questId) {
                return $fiche;
            }
        }

        return null;
    }

    private function createHuntQuest(KernelBrowser $client, string $token, int $pnjId): int
    {
        $saved = $this->jsonRequest($client, '/api/quest/editor/save', [
            'id' => 0,
            'name' => 'Battue du journal (test)',
            'introduction' => 'Les loups rôdent.',
            'minimalLevel' => 0,
            'alignementId' => 0,
            'objetId' => 0,
            'prerequisiteQueteId' => 0,
            'sequences' => [[
                'id' => 0,
                'clientKey' => 'new-journal-1',
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
                    'karma' => 0,
                    'nextSequenceKey' => '__END__',
                    'recompense' => ['money' => 0, 'experience' => 0, 'quantity' => 0, 'objetId' => 0, 'equipementId' => 0, 'consommableId' => 0],
                ]],
            ]],
        ], $token);
        $this->assertResponseIsSuccessful();

        return $saved['id'];
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
        $unique = uniqid('journalq', true);
        $user = [
            'pseudo' => 'Jq' . substr(md5($unique), 0, 10),
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
}
