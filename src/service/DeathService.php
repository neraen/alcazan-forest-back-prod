<?php


namespace App\service;

use App\Entity\DonjonInstanceMonstre;
use App\Entity\Monstre;
use App\Entity\MonstreCarreau;
use App\Entity\MonstreObjet;
use App\Entity\User;
use App\Enum\TypeCompteur;
use App\Enum\TypeItem;
use App\Repository\CarteCarreauRepository;
use App\Repository\CarteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class DeathService
{

    public function __construct(
        private MapService $mapService,
        private LevelingService $levelingService,
        private SacService $sacService,
        private MetierService $metierService,
        private CompteurJoueurService $compteurJoueurService,
        private CarteRepository $carteRepository,
        private CarteCarreauRepository $carteCarreauRepository,
        private UserRepository $userRepository,
        private DonjonInstanceService $donjonInstanceService,
        private EntityManagerInterface $entityManager
    ){}

    /**
     * Mort d'un monstre : décrément de la population et distribution du butin.
     *
     * Une ligne de butin peut exiger un MÉTIER (le dépeceur et ses peaux) : elle n'est
     * alors tirée que pour un joueur qui l'exerce au bon niveau, et elle lui rapporte de
     * l'expérience. Les lignes sans métier tombent pour tout le monde, comme avant.
     *
     * Tout se joue dans UNE transaction, avec un seul flush : l'ancien code flushait à
     * chaque objet tiré, ce qui pouvait laisser un butin à moitié distribué si la ligne
     * suivante échouait.
     *
     * La mise à mort est aussi comptée (`TypeCompteur::MONSTRE_TUE`) : c'est ce compteur,
     * et lui seul, que lit une action de quête BATTRE_MONSTRE. Il est incrémenté ICI et
     * pas dans le contrôleur, parce que c'est ici que « le monstre meurt » est vrai — les
     * monstres d'instance meurent par `dieRenfort()`, qui compte et butine pareil.
     *
     * @return string[] noms des objets réellement obtenus
     */
    public function dieMonster(MonstreCarreau $monstreCarreau, User $user): array{
        return $this->entityManager->wrapInTransaction(function () use ($monstreCarreau, $user): array {
            $monstreCarreau->setQuantity($monstreCarreau->getQuantity() - 1);
            $monstreCarreau->setCurrentLife($monstreCarreau->getMonstre()->getMaxLife());
            $this->entityManager->persist($monstreCarreau);

            $this->compteurJoueurService->incrementer(
                $user,
                TypeCompteur::MONSTRE_TUE,
                (int)$monstreCarreau->getMonstre()->getId()
            );

            $droppedItems = $this->distribuerButin($monstreCarreau->getMonstre(), $user);

            $this->entityManager->flush();

            return $droppedItems;
        });
    }

    /**
     * Mort d'un monstre d'INSTANCE (population d'une salle de donjon, renfort invoqué par
     * un boss). Même butin, même compteur qu'un monstre du monde ouvert : du point de vue
     * du joueur c'est un monstre ordinaire, seule la table diffère
     * (`donjon_instance_monstre` = runtime d'une expédition, `monstre_carreau` = décor
     * partagé, cf. l'entité). Il n'y a rien à décrémenter : la ligne reste, marquée morte,
     * et c'est elle que lisent les conditions « salle nettoyée ».
     *
     * @return string[] noms des objets réellement obtenus
     */
    public function dieRenfort(DonjonInstanceMonstre $renfort, User $user): array{
        return $this->entityManager->wrapInTransaction(function () use ($renfort, $user): array {
            $renfort->setCurrentLife(0);
            $renfort->setVivant(false);
            $this->entityManager->persist($renfort);

            $this->compteurJoueurService->incrementer(
                $user,
                TypeCompteur::MONSTRE_TUE,
                (int)$renfort->getMonstre()->getId()
            );

            $droppedItems = $this->distribuerButin($renfort->getMonstre(), $user);

            $this->entityManager->flush();

            return $droppedItems;
        });
    }

    /**
     * Tire le butin d'un monstre pour ce joueur. Ne flushe pas : l'appelant fournit la
     * transaction (l'ancien code flushait à chaque objet tiré, ce qui pouvait laisser un
     * butin à moitié distribué si la ligne suivante échouait).
     *
     * @return string[] noms des objets réellement obtenus
     */
    private function distribuerButin(Monstre $monstre, User $user): array
    {
        $droppedItems = [];

        foreach ($monstre->getMonstreObjets() as $drop){
            if(!$this->peutPrelever($user, $drop)){
                continue;
            }

            if(rand(1, $drop->getDiviseurTauxDrop()) > $drop->getTauxDrop()){
                continue;
            }

            // Le drop d'équipement n'a jamais été implémenté : plutôt que de laisser
            // un `else` vide qui annonce au joueur un butin qu'il ne reçoit pas, on
            // ignore la ligne. Elle sera reprise quand SacService la portera.
            if($drop->getTypeDrop() !== "objet"){
                continue;
            }

            $this->sacService->ajouterItem($user, TypeItem::OBJET, $drop->getObjet()->getId(), 1);
            $droppedItems[] = $drop->getObjet()->getName();

            if($drop->exigeUnMetier() && $drop->getExperienceMetier() > 0){
                $this->metierService->gagnerExperience($user, $drop->getMetier(), $drop->getExperienceMetier());
            }
        }

        return $droppedItems;
    }

    /**
     * Une ligne liée à un métier n'est prélevable que par qui l'exerce au niveau requis :
     * c'est ce qui fait exister le dépeceur. Les autres lignes ne demandent rien.
     */
    private function peutPrelever(User $user, MonstreObjet $drop): bool
    {
        if(!$drop->exigeUnMetier()){
            return true;
        }

        return $this->metierService->niveau($user, $drop->getMetier())
            >= max(1, $drop->getNiveauMetierMin());
    }

    public function diePlayer(User $user): array{
        $initialMap = $this->carteRepository->find($user->getMap()->getId());

        // Mourir en donjon renvoie au cimetière, donc HORS de l'instance : le membre doit
        // être marqué absent, sinon un solo laisserait son instance ouverte avec un
        // « présent » fantôme et le groupe le verrait encore dans la salle.
        $this->donjonInstanceService->sortir($user);

        $cimetieres = $this->carteRepository->findBy(['is_cimetiere' => true]);
        $nearestCimetiere = $this->mapService->getNearestMapInList($initialMap, $cimetieres);
        $experience = $this->levelingService->giveExpMalusAfterDeath($user->getId());

        /** todo renommer cette fonction et prendre que l'id user en params */
        $this->carteCarreauRepository->updatePlayerInCase($user);

        /**  todo faire une requete à la main */
        $startTime = new \DateTime("now");
        $startTime->modify('+30 seconds');


        $this->userRepository->updateJoueurInfoAfterDeath($startTime, $nearestCimetiere->getId(), 11, 10,
            $user->getMaxLife(), $user->getMaxMana(), $user->getId());

//        $userEntity->setSummoningSickness($startTime);
//        $userEntity->setMap($nearestCimetiere);
//        $userEntity->setCaseAbscisse(11);
//        $userEntity->setCaseOrdonnee(10);
//        $userEntity->setCurrentLife($userEntity->getMaxLife());
//        $userEntity->setCurrentMana($userEntity->getMaxMana());
//        dump($userEntity);
//        $this->entityManager->persist($userEntity);
//        $this->entityManager->flush();
        $this->carteCarreauRepository->setPlayerOnCaseInAMap($nearestCimetiere->getId(), 11, 10, $user->getId());

        // La mort est écrite par un UPDATE en DQL, qui NE PASSE PAS par l'unité de travail :
        // sans cette resynchronisation, l'entité en mémoire garde la vie négative et la
        // carte d'avant la mort. Deux conséquences observées en jeu :
        //   - la réponse renvoyait `lifeJoueur` négatif (fiche à -35/765) ;
        //   - le moindre flush ultérieur réécrivait l'état d'AVANT la mort, ressuscitant le
        //     joueur sur place — il se déplaçait au lieu d'être au cimetière.
        $this->entityManager->refresh($user);

        return [
            'experience' => $experience,
            'mapId' => $nearestCimetiere->getId()
        ];
    }

}