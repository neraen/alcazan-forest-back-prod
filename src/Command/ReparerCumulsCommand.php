<?php

namespace App\Command;

use App\Enum\TypeCumul;
use App\Repository\JoueurCumulRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recalcule les cumuls DÉRIVÉS depuis leurs sources de vérité.
 *
 * C'est cette commande qui rend les dénormalisations défendables. La règle qu'elle
 * matérialise : *une valeur dérivée n'est acceptable que si on sait la reconstruire.* Sans
 * elle, `joueur_cumul.boss_vaincus` serait une seconde vérité à côté de `user_boss`, sans
 * moyen d'arbitrer laquelle a raison le jour où elles divergent.
 *
 * Volontairement HORS du scheduler : ce n'est pas un filet permanent mais un outil de
 * maintenance, à lancer après un incident ou une reprise de données. La faire tourner en
 * boucle masquerait justement le bug qu'on voudrait voir.
 *
 * ⚠️ Ne recalcule QUE ce qui est dérivable :
 *  - `MONSTRES_TUES` ← `SUM(joueur_compteur.valeur)` pour `monstre_tue`
 *  - `BOSS_VAINCUS`  ← `SUM(user_boss.number_kill)`
 *
 * `XP_TOTALE`, `MORTS`, `JOUEURS_TUES`, `OR_GAGNE` et `OR_DEPENSE` n'ont AUCUNE source
 * reconstituable — ce sont des flux, pas des états. Les remettre à une valeur « calculée »
 * reviendrait à inventer un chiffre. Ils ne sont donc jamais touchés ici.
 */
#[AsCommand(
    name: 'app:cumuls:reparer',
    description: "Recalcule les cumuls dérivés (monstres, boss) depuis leurs sources de vérité"
)]
class ReparerCumulsCommand extends Command
{
    public function __construct(
        private readonly JoueurCumulRepository $cumulRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'verifier',
            null,
            InputOption::VALUE_NONE,
            "N'écrit rien : signale seulement les écarts entre les cumuls et leurs sources"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verificationSeule = (bool) $input->getOption('verifier');
        $connection = $this->entityManager->getConnection();

        $sources = [
            TypeCumul::MONSTRES_TUES->value => $connection->fetchAllKeyValue(
                "SELECT user_id, SUM(valeur) FROM joueur_compteur WHERE type = 'monstre_tue' GROUP BY user_id"
            ),
            TypeCumul::BOSS_VAINCUS->value => $connection->fetchAllKeyValue(
                'SELECT user_id, SUM(number_kill) FROM user_boss GROUP BY user_id'
            ),
        ];

        $ecarts = 0;

        foreach ($sources as $cleBrute => $valeursParJoueur) {
            $cle = TypeCumul::from($cleBrute);

            foreach ($valeursParJoueur as $userId => $attendu) {
                $userId = (int) $userId;
                $attendu = (int) $attendu;
                $actuel = $this->cumulRepository->valeurParId($userId, $cle);

                if ($actuel === $attendu) {
                    continue;
                }

                ++$ecarts;
                $output->writeln(sprintf(
                    'Joueur %d — %s : %d en base, %d attendu%s',
                    $userId,
                    $cle->value,
                    $actuel,
                    $attendu,
                    $verificationSeule ? '' : ' → corrigé'
                ));

                if (!$verificationSeule) {
                    $this->cumulRepository->ecraserParId($userId, $cle, $attendu);
                }
            }
        }

        $output->writeln($ecarts === 0
            ? 'Aucun écart : les cumuls dérivés concordent avec leurs sources.'
            : sprintf('%d écart(s) %s.', $ecarts, $verificationSeule ? 'détecté(s)' : 'corrigé(s)'));

        return Command::SUCCESS;
    }
}
