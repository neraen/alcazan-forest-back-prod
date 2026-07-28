<?php

namespace App\Command;

use App\service\EchangeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Filet de sécurité du système d'échange : clôt les sessions dont l'expiration est dépassée
 * et libère leurs réservations. Lancée toutes les minutes par le service "scheduler" du
 * docker-compose. L'expiration lazy (EchangeService, à chaque accès) couvre déjà la plupart
 * des cas — cette commande rattrape les sessions abandonnées que personne ne consulte plus.
 */
#[AsCommand(
    name: 'app:echanges:expirer',
    description: 'Expire les sessions d\'échange périmées et libère leurs réservations'
)]
class ExpirerEchangesCommand extends Command
{
    public function __construct(private readonly EchangeService $echangeService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expirees = $this->echangeService->expirerSessionsPerimees();

        if ($expirees > 0) {
            $output->writeln(sprintf(
                '[%s] %d session(s) d\'échange expirée(s)',
                (new \DateTime())->format('Y-m-d H:i:s'),
                $expirees
            ));
        }

        return Command::SUCCESS;
    }
}
