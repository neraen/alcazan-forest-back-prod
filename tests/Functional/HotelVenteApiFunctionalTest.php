<?php

namespace App\Tests\Functional;

use App\Config\HotelVenteConfig;
use App\Entity\HotelVente;
use App\Entity\Inventaire;
use App\Entity\InventaireObjet;
use App\Entity\Objet;
use App\Entity\User;
use App\Enum\StatutHotelVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels de l'hôtel des ventes contre chusei_test.
 *
 * Deux comptes par test (emails uniques, pas de nettoyage requis). Contrairement à l'échange,
 * aucune contrainte de position : le marché est asynchrone, c'est tout son intérêt. Les comptes
 * neufs démarrent avec 10 pièces d'or, on les enrichit à la main quand un test a besoin de plus.
 */
class HotelVenteApiFunctionalTest extends WebTestCase
{
    /* ---------------------------------------------------------------- */
    /* Cycle nominal                                                     */
    /* ---------------------------------------------------------------- */

    public function testLeCycleCompletTransfereOrEtObjet(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, $acheteur, $tokenAcheteur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 5);
        $this->donnerOr($vendeur['id'], 100);
        $this->donnerOr($acheteur['id'], 1000);

        // Le vendeur dépose 3 exemplaires à 200 po.
        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 3, 'prix' => 200,
        ], $tokenVendeur);
        $this->assertResponseIsSuccessful();
        $annonceId = $depot['annonce']['id'];
        $frais = HotelVenteConfig::fraisDepot(200);

        $this->assertSame(110 - $frais, $depot['money'], "Les frais sont prélevés au dépôt.");
        $this->assertSame(2, $this->quantitePossedee($vendeur['id'], $objetId), "Les 3 lots ont quitté le sac.");

        // L'acheteur le voit au catalogue.
        $catalogue = $this->jsonRequest($client, '/api/hotel/catalogue', [], $tokenAcheteur);
        $this->assertResponseIsSuccessful();
        $trouvee = $this->trouverAnnonce($catalogue['annonces'], $annonceId);
        $this->assertNotNull($trouvee, "Le lot déposé doit figurer au catalogue.");
        $this->assertFalse($trouvee['estMien']);
        $this->assertSame(200, $trouvee['prix']);

        // …et l'achète.
        $achat = $this->jsonRequest($client, '/api/hotel/acheter', [
            'annonceId' => $annonceId, 'prixAttendu' => 200,
        ], $tokenAcheteur);
        $this->assertResponseIsSuccessful();
        $this->assertSame('vendue', $achat['annonce']['statut']);
        $this->assertSame(810, $achat['money'], "L'acheteur a payé 200 po sur 1010.");

        // Les ressources ont changé de mains.
        $this->assertSame(3, $this->quantitePossedee($acheteur['id'], $objetId));
        $this->assertSame(2, $this->quantitePossedee($vendeur['id'], $objetId));

        $donneesVendeur = $this->jsonRequest($client, '/api/joueur/data/minimal', [], $tokenVendeur);
        $this->assertSame(
            110 - $frais + 200,
            $donneesVendeur['money'],
            "Le vendeur touche 100 % du prix : la commission a été prise au dépôt."
        );
    }

    public function testUnObjetMisEnVenteNEstPlusVendableAuMarchand(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 2);
        $this->donnerOr($vendeur['id'], 100);

        $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 2, 'prix' => 50,
        ], $tokenVendeur);
        $this->assertResponseIsSuccessful();

        // Le séquestre est un RETRAIT, pas une réservation : l'échoppe ne voit plus rien.
        $reponse = $this->jsonRequest($client, '/api/joueur/sell/shop', [
            'type' => 'objet', 'id' => $objetId, 'quantite' => 1,
        ], $tokenVendeur);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("inventaire", $reponse['error']);
    }

    /* ---------------------------------------------------------------- */
    /* Concurrence                                                       */
    /* ---------------------------------------------------------------- */

    public function testUnLotDejaVenduRepond409(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, $acheteur, $tokenAcheteur] = $this->deuxJoueurs($client);
        $troisieme = $this->registerUser($client);
        $tokenTroisieme = $this->login($client, $troisieme);

        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 100);
        $this->donnerOr($acheteur['id'], 500);
        $this->donnerOr($troisieme['id'], 500);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);
        $annonceId = $depot['annonce']['id'];

        $this->jsonRequest($client, '/api/hotel/acheter', [
            'annonceId' => $annonceId, 'prixAttendu' => 100,
        ], $tokenAcheteur);
        $this->assertResponseIsSuccessful();

        // Le second acheteur avait le même écran : il doit être éconduit, pas débité.
        $reponse = $this->jsonRequest($client, '/api/hotel/acheter', [
            'annonceId' => $annonceId, 'prixAttendu' => 100,
        ], $tokenTroisieme);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('hotel_vente_indisponible', $reponse['code']);
        $this->assertSame('vendue', $reponse['annonce']['statut'], "Le 409 doit porter l'état frais.");

        $donnees = $this->jsonRequest($client, '/api/joueur/data/minimal', [], $tokenTroisieme);
        $this->assertSame(510, $donnees['money'], "Un achat refusé ne débite rien.");
        $this->assertSame(0, $this->quantitePossedee($troisieme['id'], $objetId));
    }

    public function testUnPrixPerimeRepond409(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, $acheteur, $tokenAcheteur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 100);
        $this->donnerOr($acheteur['id'], 500);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);

        $reponse = $this->jsonRequest($client, '/api/hotel/acheter', [
            'annonceId' => $depot['annonce']['id'], 'prixAttendu' => 50,
        ], $tokenAcheteur);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('hotel_vente_indisponible', $reponse['code']);
        $this->assertSame(100, $reponse['annonce']['prix']);
    }

    /* ---------------------------------------------------------------- */
    /* Garde-fous                                                        */
    /* ---------------------------------------------------------------- */

    public function testOnNePeutPasAcheterSonPropreLot(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 500);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);

        $reponse = $this->jsonRequest($client, '/api/hotel/acheter', [
            'annonceId' => $depot['annonce']['id'], 'prixAttendu' => 100,
        ], $tokenVendeur);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('votre propre lot', $reponse['error']);
    }

    public function testUnAchatSansOrEstRefuseSansRienDeplacer(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, $acheteur, $tokenAcheteur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        // Assez pour couvrir les frais d'un lot à 5 000 po (250 po) : c'est l'ACHETEUR qu'on
        // veut voir refusé ici, pas le vendeur.
        $this->donnerOr($vendeur['id'], 1000);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 5000,
        ], $tokenVendeur);
        $this->assertResponseIsSuccessful();

        $reponse = $this->jsonRequest($client, '/api/hotel/acheter', [
            'annonceId' => $depot['annonce']['id'], 'prixAttendu' => 5000,
        ], $tokenAcheteur);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString("assez d'or", $reponse['error']);
        $this->assertSame(0, $this->quantitePossedee($acheteur['id'], $objetId));
        $this->assertSame(
            StatutHotelVente::EN_VENTE,
            $this->relireAnnonce($depot['annonce']['id'])->getStatut(),
            "Un achat refusé laisse le lot en vente."
        );
    }

    public function testLeRetraitRendLObjetSansRembourserLesFrais(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 4);
        $this->donnerOr($vendeur['id'], 100);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 3, 'prix' => 200,
        ], $tokenVendeur);
        $frais = HotelVenteConfig::fraisDepot(200);
        $this->assertSame(1, $this->quantitePossedee($vendeur['id'], $objetId));

        $retrait = $this->jsonRequest($client, '/api/hotel/retirer', [
            'annonceId' => $depot['annonce']['id'],
        ], $tokenVendeur);
        $this->assertResponseIsSuccessful();
        $this->assertSame('retiree', $retrait['annonce']['statut']);
        $this->assertSame(4, $this->quantitePossedee($vendeur['id'], $objetId), "Le lot revient au sac.");
        $this->assertSame(110 - $frais, $retrait['money'], "Les frais de dépôt restent perdus.");
    }

    public function testUnTiersNePeutPasRetirerLeLotDUnAutre(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, , $tokenAutre] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 100);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);

        $this->jsonRequest($client, '/api/hotel/retirer', [
            'annonceId' => $depot['annonce']['id'],
        ], $tokenAutre);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testLePlafondDAnnoncesEstOpposeAuVendeur(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], HotelVenteConfig::ANNONCES_MAX_PAR_JOUEUR + 1);
        $this->donnerOr($vendeur['id'], 10000);

        for ($depose = 0; $depose < HotelVenteConfig::ANNONCES_MAX_PAR_JOUEUR; ++$depose) {
            $this->jsonRequest($client, '/api/hotel/vendre', [
                'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 10,
            ], $tokenVendeur);
            $this->assertResponseIsSuccessful();
        }

        $reponse = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 10,
        ], $tokenVendeur);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('lots en vente', $reponse['error']);
        $this->assertSame(1, $this->quantitePossedee($vendeur['id'], $objetId), "Le dépôt refusé ne retire rien.");
    }

    /* ---------------------------------------------------------------- */
    /* Expiration                                                        */
    /* ---------------------------------------------------------------- */

    public function testLExpirationRendLObjetAuVendeur(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 3);
        $this->donnerOr($vendeur['id'], 100);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 3, 'prix' => 200,
        ], $tokenVendeur);
        $this->assertSame(0, $this->quantitePossedee($vendeur['id'], $objetId));

        $this->perimer($depot['annonce']['id']);
        $expirees = static::getContainer()->get(\App\service\HotelVenteService::class)->expirerVentesPerimees();

        $this->assertGreaterThanOrEqual(1, $expirees);
        $this->assertSame(3, $this->quantitePossedee($vendeur['id'], $objetId), "L'invendu revient au sac.");
        $this->assertSame(StatutHotelVente::EXPIREE, $this->relireAnnonce($depot['annonce']['id'])->getStatut());
    }

    public function testUnLotPerimeNApparaitPlusAuCatalogue(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, , $tokenAcheteur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 100);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);
        $this->perimer($depot['annonce']['id']);

        // L'expiration est PARESSEUSE : la ligne est encore `en_vente` en base, mais le
        // catalogue ne doit pas proposer un lot que l'achat refuserait aussitôt.
        $catalogue = $this->jsonRequest($client, '/api/hotel/catalogue', [], $tokenAcheteur);
        $this->assertNull($this->trouverAnnonce($catalogue['annonces'], $depot['annonce']['id']));
    }

    /* ---------------------------------------------------------------- */
    /* Catalogue                                                         */
    /* ---------------------------------------------------------------- */

    public function testLaRechercheFiltreSurLeNomDeLItem(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, , $tokenAcheteur] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 100);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);
        $nom = $depot['annonce']['item']['nom'];

        $trouve = $this->jsonRequest($client, '/api/hotel/catalogue', ['recherche' => $nom], $tokenAcheteur);
        $this->assertResponseIsSuccessful();
        $this->assertNotNull($this->trouverAnnonce($trouve['annonces'], $depot['annonce']['id']));

        $vide = $this->jsonRequest($client, '/api/hotel/catalogue', [
            'recherche' => 'zzz-aucun-item-ne-porte-ce-nom',
        ], $tokenAcheteur);
        $this->assertSame(0, $vide['total'], "Une recherche sans correspondance ne rend AUCUNE annonce.");
    }

    public function testMesVentesNeMontreQueLesSiennes(): void
    {
        $client = static::createClient();
        [$vendeur, $tokenVendeur, , $tokenAutre] = $this->deuxJoueurs($client);
        $objetId = $this->donnerObjets($vendeur['id'], 1);
        $this->donnerOr($vendeur['id'], 100);

        $depot = $this->jsonRequest($client, '/api/hotel/vendre', [
            'type' => 'objet', 'itemId' => $objetId, 'quantite' => 1, 'prix' => 100,
        ], $tokenVendeur);

        $miennes = $this->jsonRequest($client, '/api/hotel/mes-ventes', [], $tokenVendeur);
        $this->assertResponseIsSuccessful();
        $this->assertNotNull($this->trouverAnnonce($miennes['actives'], $depot['annonce']['id']));
        $this->assertSame(1, $miennes['emplacementsUtilises']);

        $autres = $this->jsonRequest($client, '/api/hotel/mes-ventes', [], $tokenAutre);
        $this->assertNull($this->trouverAnnonce($autres['actives'], $depot['annonce']['id']));
        $this->assertSame(0, $autres['emplacementsUtilises']);
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                           */
    /* ---------------------------------------------------------------- */

    /** @return array{0: array, 1: string, 2: array, 3: string} vendeur, tokenVendeur, acheteur, tokenAcheteur */
    private function deuxJoueurs(KernelBrowser $client): array
    {
        $vendeur = $this->registerUser($client);
        $tokenVendeur = $this->login($client, $vendeur);
        $acheteur = $this->registerUser($client);
        $tokenAcheteur = $this->login($client, $acheteur);

        return [$vendeur, $tokenVendeur, $acheteur, $tokenAcheteur];
    }

    private function trouverAnnonce(array $annonces, int $annonceId): ?array
    {
        foreach ($annonces as $annonce) {
            if ($annonce['id'] === $annonceId) {
                return $annonce;
            }
        }

        return null;
    }

    /** Ramène l'expiration d'une annonce dans le passé, pour éprouver l'expiration. */
    private function perimer(int $annonceId): void
    {
        $entityManager = $this->entityManager();
        $annonce = $entityManager->find(HotelVente::class, $annonceId);
        $annonce->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $entityManager->flush();
        $entityManager->clear();
    }

    private function relireAnnonce(int $annonceId): HotelVente
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->find(HotelVente::class, $annonceId);
    }

    private function donnerOr(int $userId, int $montant): void
    {
        $entityManager = $this->entityManager();
        $user = $entityManager->find(User::class, $userId);
        $user->setMoney($user->getMoney() + $montant);
        $entityManager->flush();
        $entityManager->clear();
    }

    /** Donne `$quantite` exemplaires du premier Objet du contenu ; renvoie son id. */
    private function donnerObjets(int $userId, int $quantite): int
    {
        $entityManager = $this->entityManager();
        $objet = $entityManager->getRepository(Objet::class)->findOneBy([]);
        $this->assertNotNull($objet, 'Le seed de contenu doit fournir au moins un objet.');

        $inventaire = $entityManager->getRepository(Inventaire::class)->findOneBy(['user' => $userId]);
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
        $unique = uniqid('hdv', true);
        $user = [
            'pseudo' => 'Hdv' . substr(md5($unique), 0, 10),
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
