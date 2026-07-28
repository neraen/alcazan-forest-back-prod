<?php

namespace App\Controller;

use App\DTO\Donjon\GroupeDTO;
use App\DTO\Donjon\PorteDTO;
use App\DTO\Donjon\RenfortDTO;
use App\Entity\Donjon;
use App\Exception\DonjonException;
use App\Repository\CarteCarreauRepository;
use App\Repository\DonjonInstanceMonstreRepository;
use App\Repository\SortilegeRepository;
use App\service\CaracteristiqueService;
use App\service\DeathService;
use App\service\DonjonCombatService;
use App\service\SpellService;
use App\service\DonjonGroupeService;
use App\service\DonjonInstanceService;
use App\service\DonjonNormalizer;
use App\service\LevelingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints joueur du système de donjons. Toute la logique vit dans
 * DonjonGroupeService (le lobby) et DonjonInstanceService (l'instance) ; les erreurs
 * métier (DonjonException) sortent en 400 avec message FR, comme pour les quêtes.
 *
 * L'entrée en solo n'a PAS d'endpoint dédié : elle passe par le franchissement de wrap
 * habituel (`/api/joueur/map/update_position`), qui crée l'instance au passage. Ce
 * contrôleur ne sert qu'à composer un groupe avant d'entrer.
 */
#[Route("/api/donjon", name: "api_donjon_")]
class DonjonController extends AbstractController
{
    public function __construct(
        private readonly DonjonGroupeService $groupeService,
        private readonly DonjonInstanceService $instanceService,
        private readonly DonjonCombatService $combatService,
        private readonly DonjonNormalizer $normalizer,
        private readonly CarteCarreauRepository $carteCarreauRepository
    ){}

    /**
     * Ce que le joueur voit en cliquant sur une porte de donjon : le donjon, l'état de
     * son verrou du jour, son groupe et les groupes ouverts. Tout ce qu'il faut pour
     * peupler la modale d'entrée en une requête.
     */
    #[Route("/porte", name: "porte", methods: ["POST"])]
    public function porte(#[MapRequestPayload] PorteDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $donjon = $this->donjonDerriereLaPorte($dto->carteCarreauId);
            $user = $this->getUser();

            return $this->normalizer->normalizePorte(
                $user,
                $donjon,
                $this->groupeService->groupesOuverts($donjon),
                $this->groupeService->groupeDuJoueur($user)
            );
        });
    }

    #[Route("/groupe/creer", name: "groupe_creer", methods: ["POST"])]
    public function creerGroupe(#[MapRequestPayload] PorteDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $donjon = $this->donjonDerriereLaPorte($dto->carteCarreauId);
            $groupe = $this->groupeService->creer($this->getUser(), $donjon);

            return ['groupe' => $this->normalizer->normalizeGroupe($groupe)];
        });
    }

    #[Route("/groupe/rejoindre", name: "groupe_rejoindre", methods: ["POST"])]
    public function rejoindreGroupe(#[MapRequestPayload] GroupeDTO $dto): Response
    {
        return $this->handle(function () use ($dto): array {
            $groupe = $this->groupeService->rejoindre($this->getUser(), $dto->groupeId);

            return ['groupe' => $this->normalizer->normalizeGroupe($groupe)];
        });
    }

    #[Route("/groupe/quitter", name: "groupe_quitter", methods: ["POST"])]
    public function quitterGroupe(): Response
    {
        return $this->handle(function (): array {
            $this->groupeService->quitter($this->getUser());

            return ['groupe' => null];
        });
    }

    /** Le meneur fait entrer tout le monde : une instance, un verrou par joueur. */
    #[Route("/groupe/lancer", name: "groupe_lancer", methods: ["POST"])]
    public function lancerGroupe(): Response
    {
        return $this->handle(function (): array {
            $instance = $this->groupeService->lancer($this->getUser());
            $user = $this->getUser();

            return [
                'instance' => $this->normalizer->normalizeInstance($instance),
                'mapId' => $user->getMap()->getId(),
                'abscisse' => $user->getCaseAbscisse(),
                'ordonnee' => $user->getCaseOrdonnee(),
            ];
        });
    }

    /** État courant : sert au front à se resynchroniser après un évènement Mercure. */
    #[Route("/groupe/courant", name: "groupe_courant", methods: ["POST"])]
    public function groupeCourant(): Response
    {
        return $this->handle(function (): array {
            return ['groupe' => $this->normalizer->normalizeGroupe(
                $this->groupeService->groupeDuJoueur($this->getUser())
            )];
        });
    }

    /**
     * État de combat de l'instance courante. C'est l'appel que fait le front pendant une
     * rencontre : il JOUE LE TICK au passage (zones échues, mécaniques de la phase), ce
     * qui est le seul moyen de faire avancer une rencontre sans tâche planifiée.
     */
    #[Route("/combat", name: "combat", methods: ["POST"])]
    public function combat(): Response
    {
        return $this->handle(function (): array {
            $instance = $this->instanceService->instanceCourante($this->getUser());
            if ($instance === null) {
                return ['instanceId' => null, 'boss' => null, 'zones' => [], 'renforts' => [], 'menaces' => []];
            }

            return $this->combatService->etatCombat($instance, $this->instanceService->bossDeLInstance($instance));
        });
    }

    /**
     * Frappe un monstre d'instance (population d'une salle, renfort invoqué par un boss).
     *
     * La RÉPONSE a volontairement la même forme que `/api/joueur/attack/monster` : côté
     * joueur c'est un monstre ordinaire (même expérience au coup, même butin à la mort,
     * même message), et le front n'a donc rien à normaliser. Seule la cible diffère —
     * `donjon_instance_monstre` n'est pas un `monstre_carreau`, d'où cet endpoint.
     */
    #[Route("/renfort/attaquer", name: "renfort_attaquer", methods: ["POST"])]
    public function attaquerRenfort(
        #[MapRequestPayload] RenfortDTO $dto,
        SortilegeRepository $sortilegeRepository,
        DonjonInstanceMonstreRepository $renfortRepository,
        SpellService $spellService,
        CaracteristiqueService $caracteristiqueService,
        DeathService $deathService,
        LevelingService $levelingService
    ): Response {
        return $this->handle(function () use ($dto, $sortilegeRepository, $renfortRepository, $spellService, $caracteristiqueService, $deathService, $levelingService): array {
            $user = $this->getUser();
            $instance = $this->instanceService->instanceCourante($user);
            if ($instance === null) {
                throw new DonjonException("Vous n'êtes dans aucun donjon.");
            }

            $spell = $sortilegeRepository->find($dto->spellId);
            $renfort = $renfortRepository->find($dto->renfortId);
            if ($spell === null || $renfort === null) {
                throw new DonjonException("Cible ou sort introuvable.");
            }

            $nomMonstre = $renfort->getMonstre()->getName();
            $degats = (int)$spellService->getSpellDamageForUser($user, $spell);
            $resultat = $this->combatService->frapperRenfort(
                $instance,
                $renfort,
                $user,
                $spell,
                $degats,
                $caracteristiqueService->getPlayerArmor($user)
            );

            // Butin et compteur de mise à mort : c'est DeathService, comme pour n'importe
            // quel monstre — un monstre de donjon qui ne rapporterait rien ne serait pas
            // un monstre, juste un péage.
            $droppedItems = $resultat['mort'] ? $deathService->dieRenfort($renfort, $user) : [];

            $experience = mt_rand(180, 240);
            $newExperience = $levelingService->giveExperienceToAPlayer($experience, $user->getId());

            $mapId = $user->getMap()?->getId();
            $estMort = false;
            if ($user->getCurrentLife() <= 0) {
                $statsAfterDeath = $deathService->diePlayer($user);
                $newExperience['experience'] = $statsAfterDeath['experience'];
                $mapId = $statsAfterDeath['mapId'];
                $estMort = true;
            }

            $message = "Vous infligez {$resultat['degats']} points de dommages et vous gagnez {$experience} points d'expériences <br />";
            if (!$resultat['mort']) {
                $message .= "{$nomMonstre} riposte et vous inflige {$resultat['riposte']} points de dommage <br />";
            }
            $message .= isset($droppedItems[0])
                ? "<span>En mourrant le monstre laisse tomber ceci : <strong>{$droppedItems[0]}</strong></span> <br />"
                : "";
            $message .= $estMort
                ? "<strong> Vous êtes mort suite aux blessures infligées par {$nomMonstre}. </strong>"
                : "";

            return [
                'damage' => $resultat['degats'],
                'experience' => $experience,
                'newExperience' => $newExperience['experience'],
                'level' => $newExperience['level'],
                'lifeJoueur' => $user->getCurrentLife(),
                'damageReturns' => $resultat['riposte'],
                'droppedItems' => $droppedItems,
                'mapId' => $mapId,
                'pa' => $user->getActionPoint(),
                'message' => $message,
                // Le front décible sur `killMessage` : c'est ce qui retire la carte de
                // cible quand la créature tombe, comme en quittant la case d'un monstre.
                'killMessage' => $resultat['mort'] ? "{$nomMonstre} s'effondre." : null,
                'mort' => $resultat['mort'],
                'vieRestante' => $resultat['vieRestante'],
                'needRefresh' => true,
            ];
        });
    }

    private function donjonDerriereLaPorte(int $carteCarreauId): Donjon
    {
        $case = $this->carteCarreauRepository->find($carteCarreauId);
        if ($case === null || !$case->getIsWrap()) {
            throw new DonjonException("Cette case n'est pas un passage.");
        }

        // La porte doit être à portée : le serveur ne se fie pas au clic du front.
        $user = $this->getUser();
        $estAdjacente = $case->getCarte()?->getId() === $user->getMap()?->getId()
            && abs($case->getAbscisse() - $user->getCaseAbscisse()) <= 1
            && abs($case->getOrdonnee() - $user->getCaseOrdonnee()) <= 1;
        if (!$estAdjacente) {
            throw new DonjonException("Vous êtes trop loin de cette porte.");
        }

        $donjon = $this->instanceService->donjonDeLaCarte((int)$case->getTargetMapId());
        if ($donjon === null) {
            throw new DonjonException("Cette porte ne mène à aucun donjon.");
        }

        return $donjon;
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (DonjonException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
