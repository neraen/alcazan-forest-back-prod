<?php

namespace App\Tests\Functional;

use App\Config\PvpConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Duel entre joueurs : les garde-fous serveur, et l'attribution de la mort.
 *
 * `testLesPointsDActionSontReellementDecomptes` est le test qui compte : avant ce lot,
 * `doDamage` (le chemin PvP) ne touchait JAMAIS `actionPoint`, alors que `doDamageOnBoss` le
 * faisait — attaquer un joueur était gratuit et illimité. Les autres vérifient des refus qui
 * n'existaient tout simplement pas (carte, portée, réapparition, feu ami).
 */
class PvpApiFunctionalTest extends WebTestCase
{
    /* ---------------------------------------------------------------- */
    /* Le trou principal                                                 */
    /* ---------------------------------------------------------------- */

    public function testLesPointsDActionSontReellementDecomptes(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $sort = $this->sortAttaque();

        $avant = $this->paDe($a['id']);
        $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $sort['id'],
        ], $tokenA);
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            $avant - $sort['pa'],
            $this->paDe($a['id']),
            'Le PvP était GRATUIT : doDamage ne décomptait jamais les points d\'action.'
        );
    }

    public function testUneAttaqueSansPointsDActionEstRefusee(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET action_point = 0 WHERE id = ?', [$a['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString("points d'action", $reponse['message']);
    }

    /* ---------------------------------------------------------------- */
    /* Le contrat partagé avec le front                                  */
    /* ---------------------------------------------------------------- */

    /**
     * `Spell.jsx` consomme les trois endpoints d'attaque (joueur, monstre, boss) DE LA MÊME
     * FAÇON. Toute clé manquante y casse le rendu — et pas seulement l'affichage : le front
     * fait `attackStats.droppedItems[0]`, qui lève un TypeError si la clé est absente,
     * empêche `updateJoueurState`, et laisse le ciblage mort jusqu'au rechargement.
     *
     * C'est exactement ce qui s'est produit après la refonte du PvP : la réponse était plus
     * propre, mais elle ne respectait plus le contrat. Ce test est le garde-fou qui manquait.
     */
    public function testLaReponseRespecteLeContratPartageDesAttaques(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);
        $this->assertResponseIsSuccessful();

        foreach (['damage', 'experience', 'newExperience', 'level', 'lifeJoueur',
                  'damageReturns', 'droppedItems', 'killMessage', 'message', 'pa',
                  'needRefresh'] as $cle) {
            $this->assertArrayHasKey($cle, $reponse, "Clé « $cle » attendue par Spell.jsx.");
        }

        $this->assertIsArray(
            $reponse['droppedItems'],
            "Un duel ne rapporte pas de butin, mais la clé doit exister : le front y accède par index."
        );

        // ⚠️ Présentes ne suffit pas : elles doivent être RENSEIGNÉES. `UsernameBlock`
        // s'affiche sous condition de `joueurState.level`, et un coup sans gain d'XP
        // renvoyait `level: null` — la fiche du joueur était alors remplacée par un
        // chargement perpétuel jusqu'au F5. « Aucune XP gagnée » n'est pas « aucun niveau ».
        $this->assertNotNull($reponse['level'], "Un coup sans gain d'XP renvoie le niveau COURANT.");
        $this->assertGreaterThan(0, $reponse['level']);
        $this->assertNotNull($reponse['newExperience']);
    }

    /** Même trou côté soin : se soigner soi-même ne rapporte rien, mais on a toujours un niveau. */
    public function testUnSoinSansGainRenseigneQuandMemeLeNiveau(): void
    {
        $client = static::createClient();
        [$a, $tokenA] = $this->duel($client);
        $sort = $this->sortSoin();
        if ($sort === null) {
            $this->markTestSkipped('Le seed ne fournit aucun sort de soin.');
        }

        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$a['id']]);
        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $a['id'], 'spellId' => $sort['id'],
        ], $tokenA);
        $this->assertResponseIsSuccessful();

        $this->assertSame(0, $reponse['experience'], 'Se soigner soi-même ne rapporte rien.');
        $this->assertNotNull($reponse['level']);
        $this->assertNotNull($reponse['newExperience']);
    }

    /** `killMessage` est ce qui fait DÉCIBLER côté front : sans lui, on reste accroché au mort. */
    public function testUneMiseAMortRenseigneKillMessage(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$b['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertTrue($reponse['kill']);
        $this->assertNotNull($reponse['killMessage']);
        $this->assertTrue($reponse['needRefresh']);
    }

    /** Un refus est un 400 AVEC un message : le front le toaste, il ne doit rien casser. */
    public function testUnRefusPorteUnMessageExploitableParLeFront(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET case_abscisse = 60 WHERE id = ?', [$b['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('message', $reponse, "Le front lit `response.data.message`.");
        $this->assertNotSame('', $reponse['message']);
    }

    /* ---------------------------------------------------------------- */
    /* Les autres refus                                                  */
    /* ---------------------------------------------------------------- */

    public function testUneAttaqueHorsDePorteeEstRefusee(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET case_abscisse = 60 WHERE id = ?', [$b['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('portée', $reponse['message']);
    }

    public function testOnNAttaquePasATraversLesCartes(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET map_id = 3 WHERE id = ?', [$b['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('carte', $reponse['message']);
    }

    /** Sans ça, on achève en boucle quelqu'un qui vient de réapparaître au cimetière. */
    public function testOnNAcheverPasUnJoueurQuiVientDeReapparaitre(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET summoning_sickness = DATE_ADD(NOW(), INTERVAL 30 SECOND) WHERE id = ?', [$b['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('réapparaître', $reponse['message']);
    }

    /* ---------------------------------------------------------------- */
    /* Feu ami                                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Frapper un allié est PERMIS — et se paie en honneur ET en karma.
     *
     * Ce que ce test verrouille, c'est l'inversion : l'ancienne règle refusait le coup, ce qui
     * rendait la trahison impossible plutôt que coûteuse.
     */
    public function testFrapperUnAllieEstPermisMaisCouteHonneurEtKarma(): void
    {
        if (!PvpConfig::FEU_AMI_AUTORISE) {
            $this->markTestSkipped('Le feu ami est interdit par la configuration.');
        }

        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET alignement_id = 1 WHERE id = ?', [$b['id']]);
        $this->sql('UPDATE user SET honneur = 0, karma = 0 WHERE id = ?', [$a['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertResponseIsSuccessful();
        $this->assertNotNull($reponse['feuAmi'], "Le prix de la trahison est annoncé au joueur.");
        $this->assertSame(PvpConfig::FEU_AMI_HONNEUR_PAR_COUP, $reponse['feuAmi']['honneur']['delta']);
        $this->assertSame(PvpConfig::FEU_AMI_KARMA_PAR_COUP, $reponse['feuAmi']['karma']['delta']);
        $this->assertFalse($reponse['feuAmi']['miseAMort']);

        $etat = $this->sqlFetchAssoc('SELECT honneur, karma FROM user WHERE id = ?', [$a['id']]);
        $this->assertSame(PvpConfig::FEU_AMI_HONNEUR_PAR_COUP, (int) $etat['honneur']);
        $this->assertSame(PvpConfig::FEU_AMI_KARMA_PAR_COUP, (int) $etat['karma']);
    }

    /**
     * Achever un allié coûte davantage, et ne rapporte NI honneur NI expérience.
     *
     * Le point du test est le zéro : si `appliquerVictoire` s'appliquait aussi au feu ami, une
     * trahison rapporterait le gain de duel d'un côté et la pénalité de l'autre — et pourrait
     * se rentabiliser. La victime, elle, ne perd rien : se faire poignarder par son camp n'est
     * pas un échec au combat.
     */
    public function testAcheverUnAllieNeRapporteNiHonneurNiExperience(): void
    {
        if (!PvpConfig::FEU_AMI_AUTORISE) {
            $this->markTestSkipped('Le feu ami est interdit par la configuration.');
        }

        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET alignement_id = 1, current_life = 1 WHERE id = ?', [$b['id']]);
        $this->sql('UPDATE user SET honneur = 0, karma = 0 WHERE id = ?', [$a['id']]);
        $honneurVictimeAvant = (int) $this->sqlFetchAssoc('SELECT honneur FROM user WHERE id = ?', [$b['id']])['honneur'];

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($reponse['kill']);
        $this->assertNull($reponse['honneur'], "Il n'y a pas de victoire à célébrer.");
        $this->assertSame(0, $reponse['experience']);
        $this->assertTrue($reponse['feuAmi']['miseAMort']);
        $this->assertSame(
            PvpConfig::FEU_AMI_HONNEUR_PAR_COUP + PvpConfig::FEU_AMI_HONNEUR_MISE_A_MORT,
            $reponse['feuAmi']['honneur']['delta']
        );
        $this->assertSame(
            PvpConfig::FEU_AMI_KARMA_PAR_COUP + PvpConfig::FEU_AMI_KARMA_MISE_A_MORT,
            $reponse['feuAmi']['karma']['delta']
        );

        $this->assertSame(
            $honneurVictimeAvant,
            (int) $this->sqlFetchAssoc('SELECT honneur FROM user WHERE id = ?', [$b['id']])['honneur'],
            "La victime d'une trahison ne perd pas d'honneur."
        );

        // La trahison laisse sa trace : la pénalité, elle, se dissout dans `user.honneur`.
        $evenement = $this->sqlFetchAssoc(
            "SELECT JSON_EXTRACT(contexte, '$.feuAmi') AS feu_ami
             FROM evenement_jeu WHERE type = 'mort_joueur' AND cible_user_id = ? ORDER BY id DESC LIMIT 1",
            [$b['id']]
        );
        $this->assertSame('true', $evenement['feu_ami']);
    }

    public function testOnNeSAttaquePasSoiMeme(): void
    {
        $client = static::createClient();
        [$a, $tokenA] = $this->duel($client);

        $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $a['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    /* ---------------------------------------------------------------- */
    /* Mise à mort                                                       */
    /* ---------------------------------------------------------------- */

    public function testUneMiseAMortEstAttribueeEtCompte(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$b['id']]);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $this->sortAttaque()['id'],
        ], $tokenA);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($reponse['kill']);
        $this->assertFalse($reponse['honneur']['farm'], 'Première victoire : pas de farm.');
        $this->assertSame(PvpConfig::HONNEUR_BASE, $reponse['honneur']['vainqueur']['delta'], 'Niveaux égaux.');
        $this->assertLessThan(0, $reponse['honneur']['vaincu']['delta']);

        // La mort porte sa CAUSE et son AUTEUR : c'est ce que diePlayer ne savait pas.
        $evenement = $this->sqlFetchAssoc(
            "SELECT acteur_id, cible_user_id, JSON_UNQUOTE(JSON_EXTRACT(contexte, '$.cause')) AS cause
             FROM evenement_jeu WHERE type = 'mort_joueur' AND cible_user_id = ? ORDER BY id DESC LIMIT 1",
            [$b['id']]
        );
        $this->assertSame($a['id'], (int) $evenement['acteur_id']);
        $this->assertSame('pvp', $evenement['cause']);

        $this->assertSame(1, $this->cumulDe($a['id'], 'joueurs_tues'));
        $this->assertSame(1, $this->cumulDe($b['id'], 'morts'));
    }

    /**
     * Retuer la même victime dans la fenêtre ne rapporte plus rien.
     *
     * ⚠️ Le piège que ce test verrouille : l'anti-farm doit être MESURÉ avant que la mort ne
     * soit consignée. Mesuré après, il verrait le kill courant et déclarerait farm dès la
     * PREMIÈRE victoire — c'est le bug constaté en jeu pendant ce lot.
     */
    public function testRetuerLaMemeVictimeNeRapportePlusRien(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $sort = $this->sortAttaque();

        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$b['id']]);
        $premier = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $sort['id'],
        ], $tokenA);
        $this->assertFalse($premier['honneur']['farm']);
        $this->assertGreaterThan(0, $premier['experience']);

        $this->remettreEnPosition($b['id']);
        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$b['id']]);
        $second = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $sort['id'],
        ], $tokenA);

        $this->assertTrue($second['honneur']['farm']);
        $this->assertSame(0, $second['honneur']['vainqueur']['delta'], "Le farm ne rapporte aucun honneur.");
        $this->assertSame(0, $second['experience'], "Ni expérience.");
    }

    /* ---------------------------------------------------------------- */
    /* Soin                                                              */
    /* ---------------------------------------------------------------- */

    public function testSoignerAutruiRapporteDeLExperienceEtCouteDesPa(): void
    {
        $client = static::createClient();
        [$a, $tokenA, $b] = $this->duel($client);
        $sort = $this->sortSoin();
        if ($sort === null) {
            $this->markTestSkipped('Le seed ne fournit aucun sort de soin.');
        }

        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$b['id']]);
        $avant = $this->paDe($a['id']);

        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $b['id'], 'spellId' => $sort['id'],
        ], $tokenA);
        $this->assertResponseIsSuccessful();

        $this->assertSame(PvpConfig::XP_SOIN, $reponse['experience']);
        $this->assertSame($avant - $sort['pa'], $this->paDe($a['id']));
    }

    /** Se soigner soi-même est permis, mais ne rapporte rien : sinon on farme sur soi. */
    public function testSeSoignerSoiMemeNeRapporteRien(): void
    {
        $client = static::createClient();
        [$a, $tokenA] = $this->duel($client);
        $sort = $this->sortSoin();
        if ($sort === null) {
            $this->markTestSkipped('Le seed ne fournit aucun sort de soin.');
        }

        $this->sql('UPDATE user SET current_life = 1 WHERE id = ?', [$a['id']]);
        $reponse = $this->jsonRequest($client, '/api/joueur/attack/joueur', [
            'targetId' => $a['id'], 'spellId' => $sort['id'],
        ], $tokenA);

        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $reponse['experience']);
    }

    /* ---------------------------------------------------------------- */
    /* Aides                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Deux joueurs de camps OPPOSÉS, adjacents, en pleine forme.
     *
     * @return array{0: array, 1: string, 2: array, 3: string}
     */
    private function duel(KernelBrowser $client): array
    {
        $a = $this->registerUser($client);
        $b = $this->registerUser($client);
        $tokenA = $this->login($client, $a);
        $tokenB = $this->login($client, $b);

        $this->sql(
            'UPDATE user SET alignement_id = 1, map_id = 2, case_abscisse = 10, case_ordonnee = 10,
                             action_point = 500, current_life = max_life, summoning_sickness = NULL
             WHERE id = ?',
            [$a['id']]
        );
        $this->remettreEnPosition($b['id']);

        return [$a, $tokenA, $b, $tokenB];
    }

    private function remettreEnPosition(int $userId): void
    {
        $this->sql(
            'UPDATE user SET alignement_id = 2, map_id = 2, case_abscisse = 11, case_ordonnee = 10,
                             current_life = max_life, summoning_sickness = NULL
             WHERE id = ?',
            [$userId]
        );
    }

    /** @return array{id: int, pa: int} le sort d'attaque le moins coûteux du seed */
    private function sortAttaque(): array
    {
        $ligne = $this->sqlFetchAssoc(
            "SELECT id, point_action FROM sortilege WHERE type = 'attack' AND point_action > 0
             ORDER BY point_action ASC, portee DESC LIMIT 1"
        );
        $this->assertNotFalse($ligne, "Le seed doit fournir un sort d'attaque.");

        return ['id' => (int) $ligne['id'], 'pa' => (int) $ligne['point_action']];
    }

    /** @return array{id: int, pa: int}|null */
    private function sortSoin(): ?array
    {
        $ligne = $this->sqlFetchAssoc(
            "SELECT id, point_action FROM sortilege WHERE type = 'soin' AND point_action > 0
             ORDER BY point_action ASC, portee DESC LIMIT 1"
        );

        return $ligne === false ? null : ['id' => (int) $ligne['id'], 'pa' => (int) $ligne['point_action']];
    }

    private function paDe(int $userId): int
    {
        return (int) $this->sqlFetchOne('SELECT action_point FROM user WHERE id = ?', [$userId]);
    }

    private function cumulDe(int $userId, string $cle): int
    {
        return (int) $this->sqlFetchOne(
            'SELECT COALESCE(valeur, 0) FROM joueur_cumul WHERE user_id = ? AND cle = ?',
            [$userId, $cle]
        );
    }

    private function registerUser(KernelBrowser $client): array
    {
        $unique = uniqid('pvp', true);
        $user = [
            'pseudo' => 'Pvp' . substr(md5($unique), 0, 10),
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

    private function sqlFetchAssoc(string $statement, array $params = []): mixed
    {
        return static::getContainer()->get('doctrine')->getConnection()->fetchAssociative($statement, $params);
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
