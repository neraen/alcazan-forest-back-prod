<?php

namespace App\Controller;

use App\Repository\NiveauJoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class JoueurNiveauController extends AbstractController
{

    public $niveauJoueurRepository;

    public function __construct(NiveauJoueurRepository $niveauJoueurRepository){
        $this->niveauJoueurRepository = $niveauJoueurRepository;
    }

    public function __invoke($data){
        return $this->niveauJoueurRepository->getNiveauAndExperience($this->getUser()->getId());
    }

}