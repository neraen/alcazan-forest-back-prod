<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Régénération horaire des points d'action et de mouvement de tous les joueurs.
 * Lancée toutes les heures par le service "scheduler" du docker-compose.
 */
#[AsCommand(
    name: 'app:regen-points',
    description: 'Ajoute 10 PA et 20 PM à tous les joueurs (plafonds : 600 PA / 800 PM)'
)]
class RegenPointsCommand extends Command
{
    public const PA_PER_HOUR = 10;
    public const PM_PER_HOUR = 20;
    public const PA_MAX = 600;
    public const PM_MAX = 800;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $updated = $this->connection->executeStatement(
            'UPDATE user
                SET action_point = LEAST(action_point + ?, ?),
                    mouvement_point = LEAST(mouvement_point + ?, ?)',
            [self::PA_PER_HOUR, self::PA_MAX, self::PM_PER_HOUR, self::PM_MAX]
        );

        $output->writeln(sprintf(
            '[%s] Régénération effectuée pour %d joueurs (+%d PA, +%d PM)',
            (new \DateTime())->format('Y-m-d H:i:s'),
            $updated,
            self::PA_PER_HOUR,
            self::PM_PER_HOUR
        ));

        return Command::SUCCESS;
    }
}
