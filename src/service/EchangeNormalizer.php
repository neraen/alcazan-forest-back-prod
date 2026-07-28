<?php

namespace App\service;

use App\Entity\Echange;
use App\Entity\EchangeLigne;
use App\Entity\User;
use App\Enum\TypeItem;

/**
 * Format UNIQUE de l'état d'une session d'échange, servi tel quel par l'API REST et publié
 * tel quel sur Mercure : le front remplace son état local par ce payload, sans deltas.
 * Ne jamais exposer les entités Doctrine directement.
 */
class EchangeNormalizer
{
    public function __construct(
        private readonly SacService $sacService
    ) {}

    public function normalize(Echange $echange): array
    {
        return [
            'id' => $echange->getId(),
            'version' => $echange->getVersion(),
            'statut' => $echange->getStatut()->value,
            'expiresAt' => $echange->getExpiresAt()->format(DATE_ATOM),
            'annulePar' => $echange->getAnnulePar()?->getId(),
            'joueurUn' => $this->normalizeOffre($echange, $echange->getJoueurUn()),
            'joueurDeux' => $this->normalizeOffre($echange, $echange->getJoueurDeux()),
        ];
    }

    private function normalizeOffre(Echange $echange, User $joueur): array
    {
        return [
            'joueur' => [
                'id' => $joueur->getId(),
                'pseudo' => $joueur->getPseudo(),
            ],
            'or' => $echange->getOrPropose($joueur),
            'confirme' => $echange->estConfirme($joueur),
            'lignes' => array_map(
                fn (EchangeLigne $ligne) => $this->normalizeLigne($ligne),
                $echange->getLignesDe($joueur)
            ),
        ];
    }

    private function normalizeLigne(EchangeLigne $ligne): array
    {
        try {
            $description = $this->sacService->decrireItem($ligne->getType(), $ligne->getItemId());
            $icone = $this->cheminIcone($ligne, $description['icone']);
        } catch (\DomainException) {
            // Item supprimé du contenu entre-temps : on garde la ligne lisible.
            $description = ['nom' => 'Objet inconnu'];
            $icone = null;
        }

        return [
            'ligneId' => $ligne->getId(),
            'type' => $ligne->getType()->value,
            'itemId' => $ligne->getItemId(),
            'nom' => $description['nom'],
            'icone' => $icone,
            'quantite' => $ligne->getQuantite(),
        ];
    }

    /** Chemin d'image complet, aligné sur les conventions du front (itemUtils.js). */
    private function cheminIcone(EchangeLigne $ligne, ?string $icone): ?string
    {
        if ($icone === null || $icone === '') {
            return null;
        }

        return match ($ligne->getType()) {
            TypeItem::EQUIPEMENT => sprintf(
                '/img/equipement/%s/%s',
                $this->sacService->trouverItem($ligne->getType(), $ligne->getItemId())?->getPositionEquipement()?->getName() ?? '',
                $icone
            ),
            TypeItem::CONSOMMABLE => '/img/consommables/' . $icone,
            TypeItem::OBJET => '/img/objet/' . $icone,
        };
    }
}
