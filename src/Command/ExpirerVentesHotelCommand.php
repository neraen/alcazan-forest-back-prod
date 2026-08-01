<?php

namespace App\Command;

use App\service\HotelVenteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clôt les lots de l'hôtel des ventes dont les 48 heures sont écoulées et rend chaque invendu
 * à son vendeur. Lancée toutes les minutes par le service "scheduler" du docker-compose.
 *
 * ⚠️ Contrairement à app:echanges:expirer, ce n'est PAS un simple filet derrière une
 * expiration paresseuse : c'est le seul chemin par lequel un invendu revient dans un sac.
 * Le paresseux ne couvre que les annonces que quelqu'un consulte ; un lot que plus personne
 * ne regarde ne serait jamais restitué. La désactiver, c'est confisquer des objets.
 */
#[AsCommand(
    name: 'app:hdv:expirer',
    description: "Clôt les lots périmés de l'hôtel des ventes et rend les invendus aux vendeurs"
)]
class ExpirerVentesHotelCommand extends Command
{
    public function __construct(private readonly HotelVenteService $hotelVenteService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expirees = $this->hotelVenteService->expirerVentesPerimees();

        if ($expirees > 0) {
            $output->writeln(sprintf(
                '[%s] %d lot(s) expiré(s) et rendu(s) à leur vendeur',
                (new \DateTime())->format('Y-m-d H:i:s'),
                $expirees
            ));
        }

        return Command::SUCCESS;
    }
}
