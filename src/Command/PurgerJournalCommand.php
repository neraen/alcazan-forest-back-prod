<?php

namespace App\Command;

use App\Config\JournalConfig;
use App\Repository\EvenementJeuRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Supprime les événements de jeu plus vieux que `JournalConfig::RETENTION_JOURS`.
 * Lancée une fois par heure par le service "scheduler" du docker-compose.
 *
 * Livrée avec le journal lui-même, et pas « plus tard » : c'est typiquement la tâche que
 * personne ne fait après coup, et elle coûte quarante lignes maintenant contre une
 * opération d'urgence sur une table de plusieurs giga-octets ensuite.
 *
 * Aucune exemption par type — la règle doit tenir en une ligne. Les faits qu'on veut garder
 * à vie ne sont pas dans le journal : ce sont les compteurs et les cumuls, qui ne se purgent
 * jamais. Le journal, lui, répond à « qu'est-ce qui s'est passé récemment ».
 *
 * ⚠️ La rétention ne doit jamais descendre sous la fenêtre anti-farm du PvP : l'honneur lit
 * le journal pour savoir si l'attaquant a déjà tué cette victime. Voir `JournalConfig`.
 */
#[AsCommand(
    name: 'app:journal:purger',
    description: "Supprime les événements de jeu au-delà de la durée de rétention"
)]
class PurgerJournalCommand extends Command
{
    public function __construct(private readonly EvenementJeuRepository $evenementRepository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limite = new \DateTimeImmutable(sprintf('-%d days', JournalConfig::RETENTION_JOURS));

        $supprimes = $this->evenementRepository->supprimerAvant($limite, JournalConfig::LOT_PURGE);

        if ($supprimes > 0) {
            $output->writeln(sprintf(
                '[%s] %d événement(s) purgé(s) (antérieurs au %s)',
                (new \DateTime())->format('Y-m-d H:i:s'),
                $supprimes,
                $limite->format('Y-m-d')
            ));
        }

        return Command::SUCCESS;
    }
}
