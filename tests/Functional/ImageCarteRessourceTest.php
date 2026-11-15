<?php

namespace App\Tests\Functional;

use App\Entity\CarteCarreau;
use App\Entity\Interaction;
use App\Entity\Objet;
use App\Entity\Recompense;
use App\Enum\TypeInteraction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ImageCarteRessourceTest extends KernelTestCase
{
    public function testCarteEtEtatResolventLaMemeImageSansChangerLesCoffres(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();
        try {
            $objet = (new Objet())->setName('Ressource de test')->setImage('sac.png')->setImageCarte('sauge-carte');
            $recompense = (new Recompense())->setObjet($objet)->setQuantity(1);
            $interaction = (new Interaction())->setNom('Récolte de test')->setType(TypeInteraction::RECOLTER)
                ->setSkin('ancien-sprite')->setRecompense($recompense);
            $em->persist($objet);
            $em->persist($recompense);
            $em->persist($interaction);
            $case = $em->getRepository(CarteCarreau::class)->findOneBy([]);
            self::assertNotNull($case);
            $case->setInteraction($interaction);
            $em->flush();
            $lireSkin = function () use ($em, $case): ?string {
                $cases = $em->getRepository(CarteCarreau::class)->getAllCasesOfMap($case->getCarte()->getId());
                $ligne = array_values(array_filter($cases, fn ($c) => $c['carteCarreauId'] === $case->getId()))[0];
                return $ligne['interactionSkin'];
            };
            self::assertSame('sauge-carte', $lireSkin());
            self::assertSame($interaction->getSkinCarte(), $lireSkin());
            $interaction->setType(TypeInteraction::OUVRIR);
            $em->flush();
            self::assertSame('ancien-sprite', $lireSkin(), 'Un coffre garde son image même si son butin a un sprite.');
            self::assertSame($interaction->getSkinCarte(), $lireSkin());
            $interaction->setType(TypeInteraction::RECOLTER);
            $objet->setImageCarte(null);
            $em->flush();
            self::assertSame('ancien-sprite', $lireSkin());
            self::assertSame($interaction->getSkinCarte(), $lireSkin());
            self::assertSame('sac.png', $objet->getImage());
        } finally {
            $em->getConnection()->rollBack();
            $em->clear();
        }
    }
}
