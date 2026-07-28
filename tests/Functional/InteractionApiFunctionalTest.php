<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cases interactives : conditions, coût en PA, récompense, métier et rechargement.
 *
 * Le test POSE lui-même ses interactions sur la carte 1 puis nettoie : ce sont des
 * données de contenu, un autre test ne doit pas en hériter.
 */
class InteractionApiFunctionalTest extends WebTestCase
{
    private const CARTE = 1;
    private array $casesPosees = [];

    protected function tearDown(): void
    {
        foreach ($this->casesPosees as $carteCarreauId) {
            $this->sql('DELETE FROM interaction_recharge WHERE carte_carreau_id = ?', [$carteCarreauId]);
            $this->sql('UPDATE carte_carreau SET interaction_id = NULL WHERE id = ?', [$carteCarreauId]);
        }
        parent::tearDown();
    }

    public function testUneRessourceDonneSonButinSonXpDeMetierEtCouteDesPa(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Herboriste');
        $case = $this->poserInteraction($this->creerRessource($metierId, coutPa: 3, xpMetier: 12), 8, 8);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->sql('UPDATE user SET action_point = 50 WHERE id = ?', [$user['id']]);

        $reponse = $this->executer($client, $token, $case);

        $this->assertResponseIsSuccessful();
        $this->assertNotEmpty($reponse['rewards'], 'La récolte doit donner son butin');
        $this->assertSame(47, (int)$this->sqlFetchOne('SELECT action_point FROM user WHERE id = ?', [$user['id']]));
        $this->assertSame(
            12,
            (int)$this->sqlFetchOne('SELECT experience FROM joueur_metier WHERE user_id = ? AND metier_id = ?',
                [$user['id'], $metierId]),
            "L'expérience de métier doit être créditée"
        );
    }

    public function testSansLeMetierLaRecolteEstRefuseeEtNeCoutePasDePa(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Mineur');
        $case = $this->poserInteraction($this->creerRessource($metierId, coutPa: 5, xpMetier: 20, niveauMin: 3), 8, 8);
        $this->sql('UPDATE user SET action_point = 50 WHERE id = ?', [$user['id']]);

        $reponse = $this->executer($client, $token, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Mineur', $reponse['error']);
        $this->assertSame(
            50,
            (int)$this->sqlFetchOne('SELECT action_point FROM user WHERE id = ?', [$user['id']]),
            'Un refus ne doit pas consommer de PA'
        );
    }

    public function testUnNiveauDeMetierInsuffisantEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Mineur');
        $case = $this->poserInteraction($this->creerRessource($metierId, coutPa: 0, xpMetier: 5, niveauMin: 3), 8, 8);
        $this->donnerLeMetier($user['id'], $metierId);

        $reponse = $this->executer($client, $token, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('niveau 3', $reponse['error']);
    }

    public function testSansAssezDePaLaRecolteEstRefusee(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Herboriste');
        $case = $this->poserInteraction($this->creerRessource($metierId, coutPa: 10, xpMetier: 5), 8, 8);
        $this->donnerLeMetier($user['id'], $metierId);
        $this->sql('UPDATE user SET action_point = 2 WHERE id = ?', [$user['id']]);

        $reponse = $this->executer($client, $token, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("points d'action", $reponse['error']);
    }

    /** La portée JOUEUR : chacun son cooldown. */
    public function testLaRechargeParJoueurNeBloqueQueCeluiQuiARecolte(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurEn(9, 9);
        [$deux, $tokenDeux] = $this->joueurEn(9, 10);
        $metierId = $this->creerMetier('Herboriste');
        $case = $this->poserInteraction($this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 3600), 8, 9);
        $this->donnerLeMetier($un['id'], $metierId);
        $this->donnerLeMetier($deux['id'], $metierId);

        $this->executer($client, $tokenUn, $case);
        $this->assertResponseIsSuccessful();

        $rejeu = $this->executer($client, $tokenUn, $case);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('attendre', $rejeu['error']);

        // L'autre joueur n'est pas concerné par le cooldown du premier.
        $this->executer($client, $tokenDeux, $case);
        $this->assertResponseIsSuccessful();
    }

    /** La portée MONDE : le premier arrivé vide la case pour tout le monde. */
    public function testLaRechargePartageeBloqueTousLesJoueurs(): void
    {
        $client = static::createClient();
        [, $tokenUn] = $this->joueurEn(9, 9);
        [, $tokenDeux] = $this->joueurEn(9, 10);
        $case = $this->poserInteraction($this->creerCoffreDeMonde(cooldown: 7200), 8, 9);

        $this->executer($client, $tokenUn, $case);
        $this->assertResponseIsSuccessful();

        $refus = $this->executer($client, $tokenDeux, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('attendre', $refus['error']);
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne('SELECT COUNT(*) FROM interaction_recharge WHERE carte_carreau_id = ?', [$case]),
            "La portée monde ne crée qu'UNE recharge, quelle que soit le nombre de joueurs"
        );
        $this->assertSame(
            'monde',
            $this->sqlFetchOne('SELECT cle FROM interaction_recharge WHERE carte_carreau_id = ?', [$case])
        );
    }

    public function testUneInteractionTropLoinEstRefusee(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueurEn(0, 0);
        $case = $this->poserInteraction($this->creerCoffreDeMonde(), 8, 9);

        $reponse = $this->executer($client, $token, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('trop loin', $reponse['error']);
    }

    public function testUnUsageUniqueNeSeRechargeJamais(): void
    {
        $client = static::createClient();
        [, $token] = $this->joueurEn(9, 9);
        $case = $this->poserInteraction($this->creerCoffreDeMonde(cooldown: 0, usageUnique: true), 8, 9);

        $this->executer($client, $token, $case);
        $this->assertResponseIsSuccessful();

        $refus = $this->executer($client, $token, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('déjà livré', $refus['error']);
        $this->assertNull(
            $this->sqlFetchOne('SELECT disponible_at FROM interaction_recharge WHERE carte_carreau_id = ?', [$case]),
            "Un usage unique n'a pas de date de recharge"
        );
    }

    /* ------------------------------------------------------------------ */
    /* Récolte éthique vs intensive (lot 2 de l'artisanat)                  */
    /* ------------------------------------------------------------------ */

    /**
     * Le cœur du lot : une récolte intensive tue le gisement POUR LES AUTRES. Avec la
     * seule portée JOUEUR, chacun ayant son propre délai, elle ne pourrait léser personne.
     */
    public function testUneRecolteIntensiveEpuiseLeGisementPourLesAutresJoueurs(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurEn(9, 9);
        [$deux, $tokenDeux] = $this->joueurEn(9, 10);
        $metierId = $this->creerMetier('Mineur');
        $case = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true),
            8, 9
        );
        $this->donnerLeMetier($un['id'], $metierId);
        $this->donnerLeMetier($deux['id'], $metierId);

        $this->executer($client, $tokenUn, $case, 'intensive');
        $this->assertResponseIsSuccessful();

        $refus = $this->executer($client, $tokenDeux, $case);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('saigné', $refus['error']);
        $this->assertSame(
            1,
            (int)$this->sqlFetchOne(
                "SELECT COUNT(*) FROM interaction_recharge WHERE carte_carreau_id = ? AND cle = 'monde:epuisement'",
                [$case]
            ),
            "L'épuisement est UNE ligne partagée, distincte des cooldowns personnels"
        );
    }

    /** La récolte mesurée ne retire rien aux autres : c'est toute sa contrepartie. */
    public function testUneRecolteEthiqueNEpuisePasLeGisement(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurEn(9, 9);
        [$deux, $tokenDeux] = $this->joueurEn(9, 10);
        $metierId = $this->creerMetier('Herboriste');
        $case = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true),
            8, 9
        );
        $this->donnerLeMetier($un['id'], $metierId);
        $this->donnerLeMetier($deux['id'], $metierId);

        $this->executer($client, $tokenUn, $case, 'ethique');
        $this->assertResponseIsSuccessful();

        $this->executer($client, $tokenDeux, $case, 'ethique');
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            0,
            (int)$this->sqlFetchOne(
                "SELECT COUNT(*) FROM interaction_recharge WHERE carte_carreau_id = ? AND cle LIKE '%epuisement'",
                [$case]
            )
        );
    }

    public function testLIntensifRapporteTroisFoisPlusQueLEthique(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurEn(9, 9);
        [$deux, $tokenDeux] = $this->joueurEn(9, 10);
        $metierId = $this->creerMetier('Mineur');
        $ethique = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true), 8, 9
        );
        $intensif = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true), 8, 10
        );
        $this->donnerLeMetier($un['id'], $metierId);
        $this->donnerLeMetier($deux['id'], $metierId);

        $reponseEthique = $this->executer($client, $tokenUn, $ethique, 'ethique');
        $reponseIntensive = $this->executer($client, $tokenDeux, $intensif, 'intensive');

        $quantiteEthique = $this->quantiteDuButin($reponseEthique);
        $this->assertSame(
            $quantiteEthique * 3,
            $this->quantiteDuButin($reponseIntensive),
            "L'écart de rendement doit être franc, sinon le choix n'en est pas un"
        );
    }

    /** Le mode module le délai que le joueur se coûte à LUI-MÊME. */
    public function testLeModeModuleLeCooldownPersonnel(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurEn(9, 9);
        [$deux, $tokenDeux] = $this->joueurEn(9, 10);
        $metierId = $this->creerMetier('Mineur');
        $case = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true), 8, 9
        );
        $this->donnerLeMetier($un['id'], $metierId);
        $this->donnerLeMetier($deux['id'], $metierId);

        $this->executer($client, $tokenUn, $case, 'ethique');
        $this->executer($client, $tokenDeux, $case, 'intensive');

        $secondes = fn (int $userId) => (int)$this->sqlFetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, utilisee_at, disponible_at) FROM interaction_recharge
             WHERE carte_carreau_id = ? AND cle = ?',
            [$case, 'user:' . $userId]
        );

        $this->assertSame(300, $secondes($un['id']), "Mesurée : moitié du délai de la case");
        $this->assertSame(1200, $secondes($deux['id']), "Intensive : double du délai de la case");
    }

    public function testLesDeuxModesBougentLeKarmaDansDesSensOpposes(): void
    {
        $client = static::createClient();
        [$un, $tokenUn] = $this->joueurEn(9, 9);
        [$deux, $tokenDeux] = $this->joueurEn(9, 10);
        $metierId = $this->creerMetier('Mineur');
        $ethique = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true), 8, 9
        );
        $intensif = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true), 8, 10
        );
        $this->donnerLeMetier($un['id'], $metierId);
        $this->donnerLeMetier($deux['id'], $metierId);

        $this->executer($client, $tokenUn, $ethique, 'ethique');
        $this->executer($client, $tokenDeux, $intensif, 'intensive');

        $karma = fn (int $userId) => (int)$this->sqlFetchOne('SELECT karma FROM user WHERE id = ?', [$userId]);

        $this->assertGreaterThan(0, $karma($un['id']));
        $this->assertLessThan(0, $karma($deux['id']));
    }

    /** Le client ne décide de rien : un mode sur une case ordinaire est refusé. */
    public function testUnModeSurUneCaseQuiNeProposePasLeChoixEstRefuse(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Herboriste');
        $case = $this->poserInteraction($this->creerRessource($metierId, coutPa: 0, xpMetier: 5), 8, 9);
        $this->donnerLeMetier($user['id'], $metierId);

        $reponse = $this->executer($client, $token, $case, 'intensive');

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('de cette manière', $reponse['error']);
    }

    /**
     * Non-régression : une case du lot 2 sollicitée SANS mode (vieux client) est traitée
     * en récolte mesurée — dans le doute, on ne suppose jamais que le joueur voulait
     * raser le gisement.
     */
    public function testSansModeUneCaseAChoixEstTraiteeEnRecolteMesuree(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Mineur');
        $case = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 600, recolteChoix: true), 8, 9
        );
        $this->donnerLeMetier($user['id'], $metierId);

        $this->executer($client, $token, $case);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            0,
            (int)$this->sqlFetchOne(
                "SELECT COUNT(*) FROM interaction_recharge WHERE carte_carreau_id = ? AND cle LIKE '%epuisement'",
                [$case]
            ),
            "Le défaut prudent ne doit pas saigner le gisement"
        );
        $this->assertGreaterThan(0, (int)$this->sqlFetchOne('SELECT karma FROM user WHERE id = ?', [$user['id']]));
    }

    /* ------------------------------------------------------------------ */
    /* Compteur de ressources récoltées (objectifs de quête)               */
    /* ------------------------------------------------------------------ */

    /**
     * Récolter alimente `joueur_compteur`, à la quantité RÉELLEMENT ramassée : c'est ce
     * compteur, et lui seul, que lit une action de quête RECOLTER_RESSOURCE. L'intensif
     * compte donc triple, comme il rapporte triple.
     */
    public function testLaRecolteAlimenteLeCompteurDeRessources(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        $metierId = $this->creerMetier('Herboriste');
        $case = $this->poserInteraction(
            $this->creerRessource($metierId, coutPa: 0, xpMetier: 5, cooldown: 0, recolteChoix: true), 8, 9
        );
        $this->donnerLeMetier($user['id'], $metierId);

        $reponse = $this->executer($client, $token, $case, 'intensive');
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            $this->quantiteDuButin($reponse),
            $this->compteur($user['id'], 'ressource_recoltee', 1),
            'Le compteur doit suivre la quantité ramassée, pas le nombre de gestes'
        );
    }

    /**
     * Un coffre livre lui aussi des objets, mais l'ouvrir n'est PAS récolter : une quête
     * de cueilleur ne doit pas se valider en pillant une réserve. C'est le type de
     * l'interaction qui fait la différence.
     */
    public function testOuvrirUnCoffreNeComptePasCommeUneRecolte(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->joueurEn(9, 9);
        // Coffre rendant l'objet 1, comme la ressource : seul le TYPE les distingue.
        $this->sql('INSERT INTO recompense (objet_id, money, experience, quantity) VALUES (1, NULL, NULL, 2)');
        $recompenseId = (int)$this->sqlFetchOne('SELECT MAX(id) FROM recompense');
        $this->sql("INSERT INTO interaction
            (nom, type, skin, message_succes, cout_pa, recompense_id, effect, effect_params,
             metier_id, niveau_metier_min, experience_metier, cooldown_secondes, portee_recharge, usage_unique, actif)
            VALUES (?, 'ouvrir', NULL, NULL, 0, ?, NULL, NULL, NULL, 0, 0, 600, 'monde', 0, 1)",
            ['CoffreObjet' . uniqid(), $recompenseId]);
        $case = $this->poserInteraction((int)$this->sqlFetchOne('SELECT MAX(id) FROM interaction'), 8, 9);

        $reponse = $this->executer($client, $token, $case);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $this->quantiteDuButin($reponse), 'Le coffre livre bien des objets');
        $this->assertSame(0, $this->compteur($user['id'], 'ressource_recoltee', 1));
    }

    private function compteur(int $userId, string $type, int $cibleId): int
    {
        $valeur = $this->sqlFetchOne(
            'SELECT valeur FROM joueur_compteur WHERE user_id = ? AND type = ? AND cible_id = ?',
            [$userId, $type, $cibleId]
        );

        return $valeur === false ? 0 : (int)$valeur;
    }

    /* ------------------------------------------------------------------ */

    /** Quantité totale d'objets ramassés dans une réponse d'exécution. */
    private function quantiteDuButin(array $reponse): int
    {
        $total = 0;
        foreach ($reponse['rewards'] ?? [] as $reward) {
            if ($reward['type'] === 'objet') {
                $total += (int)$reward['quantity'];
            }
        }

        return $total;
    }

    private function executer(
        KernelBrowser $client,
        string $token,
        int $carteCarreauId,
        ?string $mode = null
    ): array {
        $payload = ['carteCarreauId' => $carteCarreauId];
        if ($mode !== null) {
            $payload['mode'] = $mode;
        }

        return $this->jsonRequest($client, '/api/interaction/executer', $payload, $token);
    }

    /** Apprend le métier au joueur : depuis le lot 0 de l'artisanat, la ligne ne naît plus d'un gain d'XP. */
    private function donnerLeMetier(int $userId, int $metierId, int $niveau = 1): void
    {
        $this->sql('INSERT INTO joueur_metier (user_id, metier_id, niveau, experience, appris_at) VALUES (?, ?, ?, 0, NOW())',
            [$userId, $metierId, $niveau]);
    }

    private function creerMetier(string $nom): int
    {
        $this->sql("INSERT INTO metier (nom, description, icone, famille, niveau_max) VALUES (?, NULL, NULL, 'recolte', 100)",
            [$nom . uniqid()]);

        return (int)$this->sqlFetchOne('SELECT MAX(id) FROM metier');
    }

    private function creerRessource(
        int $metierId,
        int $coutPa,
        int $xpMetier,
        int $niveauMin = 1,
        int $cooldown = 300,
        bool $recolteChoix = false
    ): int {
        $this->sql('INSERT INTO recompense (objet_id, money, experience, quantity) VALUES (1, NULL, 10, 1)');
        $recompenseId = (int)$this->sqlFetchOne('SELECT MAX(id) FROM recompense');

        $this->sql("INSERT INTO interaction
            (nom, type, skin, message_succes, cout_pa, recompense_id, effect, effect_params,
             metier_id, niveau_metier_min, experience_metier, cooldown_secondes, portee_recharge, usage_unique,
             recolte_choix, actif)
            VALUES (?, 'recolter', NULL, NULL, ?, ?, NULL, NULL, ?, ?, ?, ?, 'joueur', 0, ?, 1)",
            ['Ressource' . uniqid(), $coutPa, $recompenseId, $metierId, $niveauMin, $xpMetier, $cooldown,
             $recolteChoix ? 1 : 0]);

        return (int)$this->sqlFetchOne('SELECT MAX(id) FROM interaction');
    }

    private function creerCoffreDeMonde(int $cooldown = 7200, bool $usageUnique = false): int
    {
        $this->sql('INSERT INTO recompense (objet_id, money, experience, quantity) VALUES (NULL, 100, 10, NULL)');
        $recompenseId = (int)$this->sqlFetchOne('SELECT MAX(id) FROM recompense');

        $this->sql("INSERT INTO interaction
            (nom, type, skin, message_succes, cout_pa, recompense_id, effect, effect_params,
             metier_id, niveau_metier_min, experience_metier, cooldown_secondes, portee_recharge, usage_unique, actif)
            VALUES (?, 'ouvrir', NULL, NULL, 0, ?, NULL, NULL, NULL, 0, 0, ?, 'monde', ?, 1)",
            ['Coffre' . uniqid(), $recompenseId, $cooldown, $usageUnique ? 1 : 0]);

        return (int)$this->sqlFetchOne('SELECT MAX(id) FROM interaction');
    }

    private function poserInteraction(int $interactionId, int $abscisse, int $ordonnee): int
    {
        $carteCarreauId = (int)$this->sqlFetchOne(
            'SELECT id FROM carte_carreau WHERE carte_id = ? AND abscisse = ? AND ordonnee = ?',
            [self::CARTE, $abscisse, $ordonnee]
        );
        $this->sql('UPDATE carte_carreau SET interaction_id = ? WHERE id = ?', [$interactionId, $carteCarreauId]);
        $this->casesPosees[] = $carteCarreauId;

        return $carteCarreauId;
    }

    /** @return array{0: array, 1: string} */
    private function joueurEn(int $abscisse, int $ordonnee): array
    {
        $client = static::getClient();
        $user = $this->registerUser($client);
        $token = $this->login($client, $user);

        $this->sql('UPDATE carte_carreau SET joueur_id = NULL WHERE joueur_id = ?', [$user['id']]);
        $this->sql('UPDATE user SET map_id = ?, case_abscisse = ?, case_ordonnee = ?, action_point = 100 WHERE id = ?',
            [self::CARTE, $abscisse, $ordonnee, $user['id']]);

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
        $unique = uniqid('inter', true);
        $user = [
            'pseudo' => 'Inter' . substr(md5($unique), 0, 10),
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
