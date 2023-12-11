<?php


namespace App\Controller;



use App\Entity\Carreau;
use App\Entity\CarteCarreau;
use App\Entity\Niveau;
use App\Repository\CarreauRepository;
use App\Repository\CarteRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class InsertController extends AbstractController
{
    private $carreauRepository;
    private $carteRepository;
    private $entityManager;

    public function __construct(CarreauRepository $carreauRepository, CarteRepository $carteRepository, EntityManagerInterface $entityManager)
    {
        $this->carreauRepository = $carreauRepository;
        $this->carteRepository = $carteRepository;
        $this->entityManager = $entityManager;
    }


    #[Route("/insert/lvl", name:"insert_lvl")]
    public function lvl(EntityManagerInterface $entityManager): Response
    {

        $experience = 10000;

        for($i = 1; $i <= 300; $i++){
            $niveau = new Niveau();
            $niveau->setNiveau($i);
            $niveau->setExperience($experience);
            $entityManager->persist($niveau);
            $entityManager->flush();
            $experience *= 1.01;
        }
        return new Response("ok");
    }


    #[Route("/insert/blankmap", name:"insert_blankmap")]
    public function blankmap(EntityManagerInterface $entityManager): Response
    {
        for($i = 0; $i <= 15; $i++){
            for($j = 0; $j <= 23; $j++){
                $case = $this->carreauRepository->find(1);
                $carte = $this->carteRepository->find(2);
                $caseCarte = new CarteCarreau();
                $caseCarte->setCarreau($case);
                $caseCarte->setCarte($carte);
                $caseCarte->setAbscisse($j);
                $caseCarte->setOrdonnee($i);
                $entityManager->persist($caseCarte);
                $entityManager->flush();
            }
        }
        return new Response("ok");
    }
}