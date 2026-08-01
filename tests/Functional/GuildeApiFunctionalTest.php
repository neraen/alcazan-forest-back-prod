<?php

namespace App\Tests\Functional;

use App\Config\GuildeConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guildes : le cycle complet et les refus.
 *
 * Le test qui justifie tout le lot est `testRejoindreUneGuildeSeVoitVraiment` : avant, une
 * adhésion écrivait dans `joueur_guilde` pendant que tout l'affichage lisait `user.guilde_id`,
 * qu'aucun code n'écrivait — rejoindre une guilde n'avait donc AUCUN effet visible. Il vérifie
 * les deux surfaces, l'état de guilde ET `data/minimal` (le chemin qui alimente la fiche de
 * personnage et la carte).
 */
class GuildeApiFunctionalTest extends WebTestCase
{
    /* ---------------------------------------------------------------- */
    /* Cycle nominal                                                     */
    /* ---------------------------------------------------------------- */

    public function testRejoindreUneGuildeSeVoitVraiment(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $recrue, $tokenRecrue] = $this->deuxJoueurs($client);

        $guilde = $this->fonder($client, $tokenBaron, 'Ordre du Test');
        $this->assertResponseIsSuccessful();

        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenRecrue);
        $this->assertResponseIsSuccessful();

        $apres = $this->jsonRequest($client, '/api/guilde/accepter', ['userId' => $recrue['id']], $tokenBaron);
        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $apres['membres']);

        // Surface 1 : l'état de guilde du nouveau membre.
        $etat = $this->jsonRequest($client, '/api/guilde/etat', [], $tokenRecrue);
        $this->assertNotNull($etat['guilde'], "Le membre accepté doit voir sa guilde.");
        $this->assertSame('membre', $etat['appartenance']['statut']);

        // Surface 2 : `data/minimal`, qui alimente la fiche et la carte. C'est CELLE qui
        // restait vide avant ce lot.
        $minimal = $this->jsonRequest($client, '/api/joueur/data/minimal', [], $tokenRecrue);
        $this->assertSame(
            $guilde['guilde']['nom'],
            $minimal['nomGuilde'],
            "C'est exactement le bug corrigé : l'adhésion écrivait ici, l'affichage lisait là-bas."
        );
    }

    public function testLeFondateurEstBaronEtPaieLaFondation(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur($client);
        $avant = $this->orDe($user['id']);

        $etat = $this->fonder($client, $token, 'Guilde du Trésor');

        $this->assertSame('baron', $etat['appartenance']['grade']);
        $this->assertSame(1, $etat['guilde']['membres']);
        $this->assertSame(
            $avant - GuildeConfig::COUT_CREATION,
            $this->orDe($user['id']),
            'Le coût de fondation est prélevé.'
        );
    }

    public function testUneCandidatureNestVisibleQueDesGradesQuiPeuventLaTraiter(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $candidat, $tokenCandidat] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Ordre Discret');

        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenCandidat);

        $vueBaron = $this->jsonRequest($client, '/api/guilde/etat', [], $tokenBaron);
        $this->assertCount(1, $vueBaron['candidatures']);

        $vueCandidat = $this->jsonRequest($client, '/api/guilde/etat', [], $tokenCandidat);
        $this->assertSame([], $vueCandidat['candidatures'], "Un candidat n'a pas à voir ses concurrents.");
        $this->assertFalse($vueCandidat['appartenance']['peutGerer']);
    }

    /* ---------------------------------------------------------------- */
    /* Refus                                                            */
    /* ---------------------------------------------------------------- */

    public function testOnNeRejointPasDeuxGuildes(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $autre, $tokenAutre] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Première Guilde');
        $this->fonder($client, $tokenAutre, 'Seconde Guilde');

        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenAutre);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUnBaronNeQuittePasSansTransmettre(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $membre, $tokenMembre] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Captive');
        $this->rejoindre($client, $guilde['guilde']['id'], $tokenMembre, $membre['id'], $tokenBaron);

        $reponse = $this->jsonRequest($client, '/api/guilde/quitter', [], $tokenBaron);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('baron', $reponse['message']);
    }

    /** Un baron SEUL peut partir : la guilde est alors dissoute avec lui. */
    public function testUnBaronSeulEmporteSaGuilde(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueur($client);
        $guilde = $this->fonder($client, $token, 'Guilde Éphémère');
        $guildeId = $guilde['guilde']['id'];

        $etat = $this->jsonRequest($client, '/api/guilde/quitter', [], $token);
        $this->assertResponseIsSuccessful();
        $this->assertNull($etat['guilde']);

        $this->assertSame(
            0,
            (int) $this->sqlFetchOne('SELECT COUNT(*) FROM guilde WHERE id = ?', [$guildeId]),
            "Une guilde à zéro membre serait inaccessible pour toujours : elle disparaît."
        );
    }

    public function testUnOfficierNExclutPasUnBaron(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $officier, $tokenOfficier] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Hiérarchique');
        $this->rejoindre($client, $guilde['guilde']['id'], $tokenOfficier, $officier['id'], $tokenBaron);
        $this->jsonRequest($client, '/api/guilde/promouvoir', ['userId' => $officier['id'], 'grade' => 'officier'], $tokenBaron);

        $this->jsonRequest($client, '/api/guilde/exclure', ['userId' => $baron['id']], $tokenOfficier);

        $this->assertSame(
            400,
            $client->getResponse()->getStatusCode(),
            "Exclure demande un grade STRICTEMENT supérieur, sinon deux officiers se courent après."
        );
    }

    public function testLeBaronNeSAttribuePasParPromotion(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $membre, $tokenMembre] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Unique');
        $this->rejoindre($client, $guilde['guilde']['id'], $tokenMembre, $membre['id'], $tokenBaron);

        $this->jsonRequest($client, '/api/guilde/promouvoir', ['userId' => $membre['id'], 'grade' => 'baron'], $tokenBaron);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUnGradeInconnuEstRefuse(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $membre, $tokenMembre] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Stricte');
        $this->rejoindre($client, $guilde['guilde']['id'], $tokenMembre, $membre['id'], $tokenBaron);

        $this->jsonRequest($client, '/api/guilde/promouvoir', ['userId' => $membre['id'], 'grade' => 'empereur'], $tokenBaron);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    /** La transmission déplace la baronnie sans jamais laisser deux barons — ni zéro. */
    public function testLaTransmissionRetrogradeLAncienBaron(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $heritier, $tokenHeritier] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Transmise');
        $this->rejoindre($client, $guilde['guilde']['id'], $tokenHeritier, $heritier['id'], $tokenBaron);

        $etat = $this->jsonRequest($client, '/api/guilde/transmettre', ['userId' => $heritier['id']], $tokenBaron);
        $this->assertResponseIsSuccessful();

        $grades = array_column($etat['membres'], 'grade', 'userId');
        $this->assertSame('baron', $grades[$heritier['id']]);
        $this->assertSame('officier', $grades[$baron['id']]);
        $this->assertCount(1, array_filter($grades, static fn ($g) => $g === 'baron'));
    }

    public function testUneGuildeCompleteRefuseLAcceptation(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $candidat, $tokenCandidat] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Comble');
        $this->sql('UPDATE guilde SET place_max = 1 WHERE id = ?', [$guilde['guilde']['id']]);

        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenCandidat);
        $this->assertResponseIsSuccessful("Une guilde pleine accepte quand même les candidatures.");

        $this->jsonRequest($client, '/api/guilde/accepter', ['userId' => $candidat['id']], $tokenBaron);
        $this->assertSame(400, $client->getResponse()->getStatusCode(), "…mais pas l'acceptation.");
    }

    public function testOnNeCandidatePasHorsDeSonAlignement(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $etranger, $tokenEtranger] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Alignée');
        $this->sql('UPDATE user SET alignement_id = 2 WHERE id = ?', [$etranger['id']]);

        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenEtranger);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    /* ---------------------------------------------------------------- */
    /* Invariant                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Au plus UNE ligne `joueur_guilde` par joueur, après n'importe quelle suite de
     * transitions. C'est l'index UNIQUE qui le garantit ; ce test vérifie qu'aucune
     * transition ne tente de le violer.
     */
    public function testAuPlusUneAppartenanceParJoueur(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $membre, $tokenMembre] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Mouvementée');

        $this->rejoindre($client, $guilde['guilde']['id'], $tokenMembre, $membre['id'], $tokenBaron);
        $this->jsonRequest($client, '/api/guilde/promouvoir', ['userId' => $membre['id'], 'grade' => 'officier'], $tokenBaron);
        $this->jsonRequest($client, '/api/guilde/exclure', ['userId' => $membre['id']], $tokenBaron);
        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenMembre);
        $this->jsonRequest($client, '/api/guilde/refuser', ['userId' => $membre['id']], $tokenBaron);
        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guilde['guilde']['id']], $tokenMembre);

        $this->assertSame(
            0,
            (int) $this->sqlFetchOne(
                'SELECT COUNT(*) FROM (SELECT user_id FROM joueur_guilde GROUP BY user_id HAVING COUNT(*) > 1) doublons'
            ),
            'Aucun joueur ne doit avoir deux appartenances.'
        );
    }

    public function testLaDissolutionEmporteToutesLesLignes(): void
    {
        $client = static::createClient();
        [$baron, $tokenBaron, $membre, $tokenMembre] = $this->deuxJoueurs($client);
        $guilde = $this->fonder($client, $tokenBaron, 'Guilde Dissoute');
        $guildeId = $guilde['guilde']['id'];
        $this->rejoindre($client, $guildeId, $tokenMembre, $membre['id'], $tokenBaron);

        $this->jsonRequest($client, '/api/guilde/dissoudre', [], $tokenBaron);
        $this->assertResponseIsSuccessful();

        $this->assertSame(0, (int) $this->sqlFetchOne('SELECT COUNT(*) FROM guilde WHERE id = ?', [$guildeId]));
        $this->assertSame(0, (int) $this->sqlFetchOne('SELECT COUNT(*) FROM joueur_guilde WHERE guilde_id = ?', [$guildeId]));
        $this->assertNull($this->jsonRequest($client, '/api/guilde/etat', [], $tokenMembre)['guilde']);
    }

    /* ---------------------------------------------------------------- */
    /* Aides                                                            */
    /* ---------------------------------------------------------------- */

    private function fonder(KernelBrowser $client, string $token, string $nom): array
    {
        return $this->jsonRequest($client, '/api/guilde/creer', [
            'nom' => $nom . ' ' . uniqid(),
        ], $token);
    }

    /** Candidature puis acceptation, le chemin normal d'entrée dans une guilde. */
    private function rejoindre(KernelBrowser $client, int $guildeId, string $tokenCandidat, int $candidatId, string $tokenDecideur): void
    {
        $this->jsonRequest($client, '/api/guilde/candidater', ['guildeId' => $guildeId], $tokenCandidat);
        $this->jsonRequest($client, '/api/guilde/accepter', ['userId' => $candidatId], $tokenDecideur);
    }

    /** @return array{0: array, 1: string} */
    private function joueur(KernelBrowser $client): array
    {
        $user = $this->registerUser($client);
        // Alignement, niveau et or : les prérequis de fondation, qu'aucun endpoint joueur ne pose.
        $this->sql('UPDATE user SET alignement_id = 1, money = ? WHERE id = ?', [GuildeConfig::COUT_CREATION * 4, $user['id']]);
        $this->sql(
            'UPDATE niveau_joueur SET niveau_id = (SELECT id FROM niveau WHERE niveau = 10) WHERE user_id = ?',
            [$user['id']]
        );

        return [$user, $this->login($client, $user)];
    }

    /** @return array{0: array, 1: string, 2: array, 3: string} */
    private function deuxJoueurs(KernelBrowser $client): array
    {
        [$un, $tokenUn] = $this->joueur($client);
        [$deux, $tokenDeux] = $this->joueur($client);

        return [$un, $tokenUn, $deux, $tokenDeux];
    }

    private function orDe(int $userId): int
    {
        return (int) $this->sqlFetchOne('SELECT money FROM user WHERE id = ?', [$userId]);
    }

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('gld', true);
        $user = [
            'pseudo' => 'Gld' . substr(md5($unique), 0, 10),
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
