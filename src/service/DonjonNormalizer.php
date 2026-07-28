<?php

namespace App\service;

use App\Entity\Donjon;
use App\Entity\DonjonGroupe;
use App\Entity\DonjonInstance;
use App\Entity\User;
use App\Repository\DonjonSalleRepository;

/**
 * Forme des payloads de donjon envoyés au front. Un seul endroit pour que la modale
 * d'entrée, les mises à jour Mercure et les réponses d'API décrivent un groupe de la
 * même façon (patron d'EchangeNormalizer).
 *
 * Aucun HTML : le front rend du texte.
 */
class DonjonNormalizer
{
    public function __construct(
        private readonly DonjonInstanceService $instanceService,
        private readonly DonjonSalleRepository $salleRepository
    ) {}

    public function normalizeDonjon(Donjon $donjon): array
    {
        return [
            'id' => $donjon->getId(),
            'nom' => $donjon->getNom(),
            'description' => $donjon->getDescription(),
            'icone' => $donjon->getIcone(),
            'niveauMin' => $donjon->getNiveauMin(),
            'tailleGroupeMax' => $donjon->getTailleGroupeMax(),
            'dureeMaxMinutes' => $donjon->getDureeMaxMinutes(),
            'heureReset' => $donjon->getHeureReset(),
        ];
    }

    public function normalizeGroupe(?DonjonGroupe $groupe): ?array
    {
        if ($groupe === null) {
            return null;
        }

        $membres = [];
        foreach ($groupe->getMembres() as $membre) {
            $user = $membre->getUser();
            $membres[] = [
                'userId' => $user->getId(),
                'pseudo' => $user->getPseudo(),
                'sexe' => $user->getSexe(),
                'classe' => $user->getClasse()?->getNom(),
                'estMeneur' => $user->getId() === $groupe->getLeader()?->getId(),
            ];
        }

        return [
            'id' => $groupe->getId(),
            'donjonId' => $groupe->getDonjon()?->getId(),
            'statut' => $groupe->getStatut()->value,
            'meneurId' => $groupe->getLeader()?->getId(),
            'membres' => $membres,
            'places' => $groupe->getDonjon()?->getTailleGroupeMax() ?? 1,
            'complet' => $groupe->estComplet(),
            'expireAt' => $groupe->getExpireAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * L'état complet de la porte : de quoi peupler la modale d'entrée sans autre requête.
     */
    public function normalizePorte(User $user, Donjon $donjon, array $groupesOuverts, ?DonjonGroupe $monGroupe): array
    {
        $instanceVerrouillee = $this->instanceService->instanceVerrouillee($user, $donjon);
        // Verrou consommé ≠ porte close : tant que l'instance est rejoignable, on y
        // retourne. Une fois sa durée max écoulée, en revanche, il n'y a plus RIEN à
        // faire ici avant le reset — le front doit le dire au lieu de proposer un retour
        // que l'entrée refuserait (c'était le bug : bouton « Retourner dans mon
        // expédition » qui répondait « revenez après 5 h »).
        $rejoignable = $instanceVerrouillee !== null
            && $this->instanceService->peutRejoindre($instanceVerrouillee);

        return [
            'donjon' => $this->normalizeDonjon($donjon),
            'verrou' => [
                'consomme' => $instanceVerrouillee !== null,
                'instanceId' => $instanceVerrouillee?->getId(),
                'statut' => $instanceVerrouillee?->getStatut()->value,
                'rejoignable' => $rejoignable,
                'prochainReset' => $this->instanceService->prochainReset($donjon)->format(\DateTimeInterface::ATOM),
            ],
            'peutEntrerSeul' => $monGroupe === null,
            'monGroupe' => $this->normalizeGroupe($monGroupe),
            'groupes' => array_values(array_filter(array_map(
                fn (DonjonGroupe $groupe) => $groupe->getId() === $monGroupe?->getId()
                    ? null
                    : $this->normalizeGroupe($groupe),
                $groupesOuverts
            ))),
        ];
    }

    public function normalizeInstance(DonjonInstance $instance): array
    {
        return [
            'id' => $instance->getId(),
            'donjonId' => $instance->getDonjon()?->getId(),
            // Les compagnons sont téléportés par le serveur : sans cette carte, leur front
            // rechargerait celle du monde ouvert où ils croient encore se trouver.
            'carteEntreeId' => $this->salleRepository->findEntree($instance->getDonjon())?->getCarte()?->getId(),
            'statut' => $instance->getStatut()->value,
            'bossCurrentLife' => $instance->getBossCurrentLife(),
            'expireAt' => $instance->getExpireAt()?->format(\DateTimeInterface::ATOM),
            'membres' => array_map(
                fn ($membre) => [
                    'userId' => $membre->getUser()->getId(),
                    'pseudo' => $membre->getUser()->getPseudo(),
                    'present' => $membre->isPresent(),
                ],
                $instance->getMembres()->toArray()
            ),
        ];
    }
}
