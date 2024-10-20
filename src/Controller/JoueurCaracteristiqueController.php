<?php


namespace App\Controller;


use App\Repository\JoueurCaracteristiqueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Guard\AuthenticatorInterface;

class JoueurCaracteristiqueController extends AbstractController
{

    public $joueurCaracteristiqueRepository;

    public function __construct(JoueurCaracteristiqueRepository $joueurCaracteristiqueRepository){
        $this->joueurCaracteristiqueRepository = $joueurCaracteristiqueRepository;
    }

    public function __invoke($data){
        return $this->joueurCaracteristiqueRepository->findBy(['user' => $this->getUser()->getId()]);
    }

}