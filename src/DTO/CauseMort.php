<?php

namespace App\DTO;

use App\Entity\User;
use App\Enum\TypeCible;

/**
 * Ce qui a tué un joueur.
 *
 * Existe parce que `DeathService::diePlayer` ne savait PAS qui tuait : sa signature ne
 * portait que la victime, et le journal ne pouvait donc consigner qu'une `cause: inconnue`.
 * Le paramètre reste OPTIONNEL sur `diePlayer` — les appels qui n'ont pas l'information
 * (ou ne l'ont pas encore branchée) compilent inchangés et journalisent « inconnue »
 * plutôt qu'une cause inventée.
 *
 * Constructeurs nommés plutôt qu'un enum + champs libres : chaque cause n'accepte que ce qui
 * la concerne, et il devient impossible de construire « mort par PvP sans tueur ».
 */
final class CauseMort
{
    private function __construct(
        public readonly string $type,
        public readonly ?User $tueur = null,
        public readonly ?TypeCible $cibleType = null,
        public readonly ?int $cibleId = null,
        public readonly array $contexte = [],
    ) {}

    /**
     * @param bool $feuAmi la victime était du camp du tueur. Consigné dans le journal parce
     *        que c'est la seule trace durable d'une trahison : la pénalité, elle, se dissout
     *        dans `user.honneur` et `user.karma` sans dire d'où elle vient.
     */
    public static function pvp(User $tueur, bool $feuAmi = false): self
    {
        return new self('pvp', tueur: $tueur, contexte: $feuAmi ? ['feuAmi' => true] : []);
    }

    public static function monstre(int $monstreId): self
    {
        return new self('monstre', cibleType: TypeCible::MONSTRE, cibleId: $monstreId);
    }

    public static function boss(int $bossId): self
    {
        return new self('boss', cibleType: TypeCible::BOSS, cibleId: $bossId);
    }

    public static function zoneDonjon(int $instanceId): self
    {
        return new self('zone_donjon', contexte: ['instanceId' => $instanceId]);
    }

    /** Ce que le journal enregistre dans `contexte`. */
    public function pourLeJournal(): array
    {
        return ['cause' => $this->type] + $this->contexte;
    }
}
