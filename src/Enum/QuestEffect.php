<?php

namespace App\Enum;

/**
 * Effets scriptés exécutables par une action SCRIPTED_EFFECT (ou une action
 * de case). C'est la whitelist serveur : chaque case est implémentée dans
 * QuestEffectRegistry — plus aucune URL arbitraire stockée en base.
 */
enum QuestEffect: string {
    /** params: {"classe": "archer"|"sorcier"|"guerrier"|"moine"} */
    case CHOISIR_CLASSE = 'choisir_classe';

    /** params: {"alignement": <id>} */
    case CHOISIR_ALIGNEMENT = 'choisir_alignement';

    case ENTRER_AUBERGE = 'entrer_auberge';

    /** params: {"bossId": <id>} */
    case RECOMPENSE_BOSS = 'recompense_boss';

    public function label(): string {
        return match ($this) {
            self::CHOISIR_CLASSE => 'Choisir une classe',
            self::CHOISIR_ALIGNEMENT => 'Choisir un alignement',
            self::ENTRER_AUBERGE => "Entrer à l'auberge",
            self::RECOMPENSE_BOSS => 'Récompense de boss',
        };
    }
}
