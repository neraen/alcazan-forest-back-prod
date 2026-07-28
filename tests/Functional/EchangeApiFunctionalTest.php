<?php

namespace App\Tests\Functional;

use App\Entity\Inventaire;
use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels du système d'échange joueur-à-joueur contre chusei_test.
 * Deux comptes par test (emails uniques, pas de nettoyage requis), positionnés
 * à la main sur des cases adjacentes — le serveur vérifie la proximité.
 */
class EchangeApiFunctionalTest extends WebTestCase
{
    /* ---------------------------------------------------------------- */
    /* Cycle nominal                                                     */
    /* ---------------------------------------------------------------- */

    public function testCycleCompletJusquAuTransfert(): void
    {
        $client = static::createClient();
        [$joueurA, $tokenA, $joueurB, $tokenB] = $this->deuxJoueursAdjacents($client);
        $objetId = $this->donnerObjets($joueurA['id'], 2);

        // A invite B.
        $reponse = $this->jsonRequest($client, '/api/echange/create', ['cibleId' => $joueurB['id']], $tokenA);
        $this->assertResponseIsSuccessful();
        $this->assertSame('en_attente', $reponse['echange']['statut']);
        $echangeId = $reponse['echange']['id'];

        // B voit l'invitation dans son état courant.
        $etat = $this->jsonRequest($client, '/api/echange/current', [], $tokenB);
        $this->assertCount(1, $etat['invitations']);
        $this->assertSame($echangeId, $etat['invitations'][0]['id']);

        // B accepte : la session s'ouvre.
        $reponse = $this->jsonRequest($client, '/api/echange/accept', ['echangeId' => $echangeId], $tokenB);
        $this->assertResponseIsSuccessful();
        $this->assertSame('ouvert', $reponse['echange']['statut']);
        $version = $reponse['echange']['version'];

        // A propose 2 objets.
        $reponse = $this->jsonRequest($client, '/api/echange/item/add', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 2, 'expectedVersion' => $version,
        ], $tokenA);
        $this->assertResponseIsSuccessful();
        $offreA = $this->offreDe($reponse['echange'], $joueurA['id']);
        $this->assertSame(2, $offreA['lignes'][0]['quantite']);
        $version = $reponse['echange']['version'];

        // B propose 5 pièces d'or (les comptes démarrent avec 10).
        $reponse = $this->jsonRequest($client, '/api/echange/or', [
            'montant' => 5, 'expectedVersion' => $version,
        ], $tokenB);
        $this->assertResponseIsSuccessful();
        $version = $reponse['echange']['version'];

        // B confirme, puis A : la double confirmation déclenche la finalisation atomique.
        $reponse = $this->jsonRequest($client, '/api/echange/confirm', ['expectedVersion' => $version], $tokenB);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->offreDe($reponse['echange'], $joueurB['id'])['confirme']);
        $version = $reponse['echange']['version'];

        $reponse = $this->jsonRequest($client, '/api/echange/confirm', ['expectedVersion' => $version], $tokenA);
        $this->assertResponseIsSuccessful();
        $this->assertSame('complete', $reponse['echange']['statut']);

        // Les ressources ont réellement changé de mains.
        $donneesA = $this->jsonRequest($client, '/api/joueur/data/minimal', [], $tokenA);
        $donneesB = $this->jsonRequest($client, '/api/joueur/data/minimal', [], $tokenB);
        $this->assertSame(15, $donneesA['money'], "A doit avoir encaissé les 5 pièces de B.");
        $this->assertSame(5, $donneesB['money'], "B doit avoir payé 5 pièces.");
        $this->assertSame(0, $this->quantitePossedee($joueurA['id'], $objetId));
        $this->assertSame(2, $this->quantitePossedee($joueurB['id'], $objetId));
    }

    public function testUneModificationInvalideLesDeuxConfirmations(): void
    {
        $client = static::createClient();
        [$joueurA, $tokenA, $joueurB, $tokenB] = $this->deuxJoueursAdjacents($client);
        $objetId = $this->donnerObjets($joueurA['id'], 3);
        $version = $this->ouvrirSession($client, $joueurB['id'], $tokenA, $tokenB);

        $reponse = $this->jsonRequest($client, '/api/echange/confirm', ['expectedVersion' => $version], $tokenB);
        $this->assertTrue($this->offreDe($reponse['echange'], $joueurB['id'])['confirme']);
        $version = $reponse['echange']['version'];

        $reponse = $this->jsonRequest($client, '/api/echange/item/add', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'expectedVersion' => $version,
        ], $tokenA);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->offreDe($reponse['echange'], $joueurA['id'])['confirme']);
        $this->assertFalse(
            $this->offreDe($reponse['echange'], $joueurB['id'])['confirme'],
            "Toute modification d'offre doit réinitialiser les DEUX confirmations."
        );
    }

    /* ---------------------------------------------------------------- */
    /* Version et concurrence                                            */
    /* ---------------------------------------------------------------- */

    public function testUneVersionObsoleteRepond409AvecLEtatFrais(): void
    {
        $client = static::createClient();
        [, $tokenA, $joueurB, $tokenB] = $this->deuxJoueursAdjacents($client);
        $version = $this->ouvrirSession($client, $joueurB['id'], $tokenA, $tokenB);

        // B agit (la version avance)…
        $this->jsonRequest($client, '/api/echange/or', ['montant' => 1, 'expectedVersion' => $version], $tokenB);
        $this->assertResponseIsSuccessful();

        // …et A rejoue l'ANCIENNE version.
        $reponse = $this->jsonRequest($client, '/api/echange/or', ['montant' => 2, 'expectedVersion' => $version], $tokenA);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('echange_conflit', $reponse['code']);
        $this->assertSame($version + 1, $reponse['echange']['version'], "Le 409 doit porter l'état frais.");
    }

    /* ---------------------------------------------------------------- */
    /* Réservations                                                      */
    /* ---------------------------------------------------------------- */

    public function testUnObjetProposeNEstPlusVendable(): void
    {
        $client = static::createClient();
        [$joueurA, $tokenA, $joueurB, $tokenB] = $this->deuxJoueursAdjacents($client);
        $objetId = $this->donnerObjets($joueurA['id'], 2);
        $version = $this->ouvrirSession($client, $joueurB['id'], $tokenA, $tokenB);

        $this->jsonRequest($client, '/api/echange/item/add', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 2, 'expectedVersion' => $version,
        ], $tokenA);
        $this->assertResponseIsSuccessful();

        // Toute la pile est réservée : la vente au marchand doit refuser.
        $reponse = $this->jsonRequest($client, '/api/joueur/sell/shop', [
            'type' => 'objet', 'id' => $objetId, 'quantite' => 1,
        ], $tokenA);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('réservé', $reponse['error']);
        $this->assertSame(2, $this->quantitePossedee($joueurA['id'], $objetId), "Rien ne doit avoir bougé.");
    }

    public function testLAnnulationLibereLesReservations(): void
    {
        $client = static::createClient();
        [$joueurA, $tokenA, $joueurB, $tokenB] = $this->deuxJoueursAdjacents($client);
        $objetId = $this->donnerObjets($joueurA['id'], 2);
        $version = $this->ouvrirSession($client, $joueurB['id'], $tokenA, $tokenB);

        $this->jsonRequest($client, '/api/echange/item/add', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 2, 'expectedVersion' => $version,
        ], $tokenA);

        $reponse = $this->jsonRequest($client, '/api/echange/cancel', [], $tokenB);
        $this->assertResponseIsSuccessful();
        $this->assertSame('annule', $reponse['echange']['statut']);

        // La pile redevient vendable.
        $this->jsonRequest($client, '/api/joueur/sell/shop', [
            'type' => 'objet', 'id' => $objetId, 'quantite' => 1,
        ], $tokenA);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->quantitePossedee($joueurA['id'], $objetId));
    }

    /* ---------------------------------------------------------------- */
    /* Garde-fous                                                        */
    /* ---------------------------------------------------------------- */

    public function testUnTiersNePeutPasAccepterLInvitation(): void
    {
        $client = static::createClient();
        [, $tokenA, $joueurB] = $this->deuxJoueursAdjacents($client);
        $intrus = $this->registerUser($client);
        $tokenIntrus = $this->login($client, $intrus);

        $reponse = $this->jsonRequest($client, '/api/echange/create', ['cibleId' => $joueurB['id']], $tokenA);
        $echangeId = $reponse['echange']['id'];

        $this->jsonRequest($client, '/api/echange/accept', ['echangeId' => $echangeId], $tokenIntrus);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testLaCreationExigeLAdjacence(): void
    {
        $client = static::createClient();
        [, $tokenA, $joueurB] = $this->deuxJoueursAdjacents($client);
        $this->deplacerJoueur($joueurB['id'], 20, 14); // à l'autre bout de la carte

        $reponse = $this->jsonRequest($client, '/api/echange/create', ['cibleId' => $joueurB['id']], $tokenA);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('adjacente', $reponse['error']);
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                           */
    /* ---------------------------------------------------------------- */

    /** @return array{0: array, 1: string, 2: array, 3: string} joueurA, tokenA, joueurB, tokenB */
    private function deuxJoueursAdjacents(KernelBrowser $client): array
    {
        $joueurA = $this->registerUser($client);
        $tokenA = $this->login($client, $joueurA);
        $joueurB = $this->registerUser($client);
        $tokenB = $this->login($client, $joueurB);

        $this->deplacerJoueur($joueurA['id'], 5, 5);
        $this->deplacerJoueur($joueurB['id'], 5, 6);

        return [$joueurA, $tokenA, $joueurB, $tokenB];
    }

    /** Invitation A→B acceptée par B ; renvoie la version courante de la session OUVERTE. */
    private function ouvrirSession(KernelBrowser $client, int $cibleId, string $tokenA, string $tokenB): int
    {
        $reponse = $this->jsonRequest($client, '/api/echange/create', ['cibleId' => $cibleId], $tokenA);
        $this->assertResponseIsSuccessful();
        $reponse = $this->jsonRequest($client, '/api/echange/accept', ['echangeId' => $reponse['echange']['id']], $tokenB);
        $this->assertResponseIsSuccessful();

        return $reponse['echange']['version'];
    }

    private function offreDe(array $echange, int $joueurId): array
    {
        return $echange['joueurUn']['joueur']['id'] === $joueurId
            ? $echange['joueurUn']
            : $echange['joueurDeux'];
    }

    private function deplacerJoueur(int $userId, int $abscisse, int $ordonnee): void
    {
        $entityManager = $this->entityManager();
        $user = $entityManager->find(User::class, $userId);
        $user->setCaseAbscisse($abscisse);
        $user->setCaseOrdonnee($ordonnee);
        $entityManager->flush();
        $entityManager->clear();
    }

    /** Donne `$quantite` exemplaires du premier Objet du contenu ; renvoie son id. */
    private function donnerObjets(int $userId, int $quantite): int
    {
        $entityManager = $this->entityManager();
        $objet = $entityManager->getRepository(Objet::class)->findOneBy([]);
        $this->assertNotNull($objet, 'Le seed de contenu doit fournir au moins un objet.');

        $inventaire = $entityManager->getRepository(Inventaire::class)
            ->findOneBy(['user' => $userId]);
        $ligne = new InventaireObjet();
        $ligne->setInventaire($inventaire);
        $ligne->setObjet($objet);
        $ligne->setQuantity($quantite);
        $entityManager->persist($ligne);
        $entityManager->flush();
        $entityManager->clear();

        return $objet->getId();
    }

    private function quantitePossedee(int $userId, int $objetId): int
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $inventaire = $entityManager->getRepository(Inventaire::class)->findOneBy(['user' => $userId]);
        $ligne = $entityManager->getRepository(InventaireObjet::class)
            ->findOneBy(['inventaire' => $inventaire, 'objet' => $objetId]);

        return $ligne === null ? 0 : (int) $ligne->getQuantity();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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
        $unique = uniqid('echange', true);
        $user = [
            'pseudo' => 'Ech' . substr(md5($unique), 0, 10),
            'email' => $unique . '@test.alcazan.fr',
            'password' => 'password123',
            'sexe' => 'masculin',
        ];

        $response = $this->jsonRequest($client, '/api/users', $user);
        $this->assertResponseStatusCodeSame(201, "L'inscription doit répondre 201");
        $user['id'] = $response['id'];

        return $user;
    }

    private function login(KernelBrowser $client, array $user): string
    {
        $response = $this->jsonRequest($client, '/api/login_check', [
            'username' => $user['email'],
            'password' => $user['password'],
        ]);
        $this->assertArrayHasKey('token', $response, 'Le login doit renvoyer un token JWT');

        return $response['token'];
    }
}
