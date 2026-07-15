<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscription : recrée le POST /api/users attendu par le front (RegisterPage).
 * Les champs NOT NULL sont initialisés ici pour que l'INSERT passe ;
 * PostRegisterSubscriber (postPersist) crée ensuite niveau, inventaire,
 * caractéristiques et place le joueur sur la carte.
 * Format d'erreur : {violations: [{propertyPath, message}]} (attendu par le front).
 */
class RegistrationController extends AbstractController
{
    #[Route("/api/users", name: "api_users_register", methods: ["POST"])]
    public function register(
        Request                     $request,
        UserRepository              $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface      $entityManager
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];

        $violations = [];

        $pseudo = trim($data['pseudo'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $sexe = $data['sexe'] ?? '';

        if ($pseudo === '') {
            $violations[] = ['propertyPath' => 'pseudo', 'message' => 'Le pseudo est obligatoire'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $violations[] = ['propertyPath' => 'email', 'message' => "L'email n'est pas valide"];
        }
        if (strlen($password) < 6) {
            $violations[] = ['propertyPath' => 'password', 'message' => 'Le mot de passe doit contenir au moins 6 caractères'];
        }
        if (!in_array($sexe, ['feminin', 'masculin'], true)) {
            $violations[] = ['propertyPath' => 'sexe', 'message' => 'Le sexe du personnage est invalide'];
        }

        if ($violations === [] && $userRepository->findOneBy(['email' => $email])) {
            $violations[] = ['propertyPath' => 'email', 'message' => 'Un compte existe déjà avec cet email'];
        }
        if ($violations === [] && $userRepository->findOneBy(['pseudo' => $pseudo])) {
            $violations[] = ['propertyPath' => 'pseudo', 'message' => 'Ce pseudo est déjà pris'];
        }

        if ($violations !== []) {
            return new JsonResponse(['violations' => $violations], Response::HTTP_BAD_REQUEST);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPseudo($pseudo);
        $user->setSexe($sexe);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        // Valeurs par défaut des colonnes NOT NULL (reprises par PostRegisterSubscriber).
        $user->setCreated(new \DateTime());
        $user->setIsActive(true);
        $user->setCurrentLife(400);
        $user->setMaxLife(400);
        $user->setCurrentMana(100);
        $user->setMaxMana(100);
        $user->setMouvementPoint(800);
        $user->setActionPoint(600);
        $user->setMoney(10);
        $user->setMaxPointCarac(0);
        $user->setActualPointCarac(0);
        $user->setRestePointCarac(0);
        $user->setCaseAbscisse(9);
        $user->setCaseOrdonnee(9);
        $user->setTutorialActive(true);

        $entityManager->persist($user);
        $entityManager->flush();

        return new JsonResponse([
            'id' => $user->getId(),
            'pseudo' => $user->getPseudo(),
            'email' => $user->getEmail(),
        ], Response::HTTP_CREATED);
    }
}
