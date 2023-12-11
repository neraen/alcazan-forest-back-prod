<?php

namespace App\Controller;

use App\Repository\CaracteristiqueRepository;
use App\Repository\JoueurCaracteristiqueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class UpdateJoueurCaracteristiqueController extends AbstractController
{

    public $joueurCaracteristiqueRepository;
    public $caracteristiqueRepository;

    public function __construct(JoueurCaracteristiqueRepository $joueurCaracteristiqueRepository, CaracteristiqueRepository $caracteristiqueRepository){
        $this->joueurCaracteristiqueRepository = $joueurCaracteristiqueRepository;
        $this->caracteristiqueRepository = $caracteristiqueRepository;
    }

    public function __invoke(Request $request){
        //return $this->joueurCaracteristiqueRepository->(['user' => $this->getUser()->getId()]);
    }

}