<?php

namespace App\Enum;
enum UnconditionalAction: int {
    case JSON = 1;
    case PASSER_DIALOGUE = 11;

    public static function getValues(){
        return array_column(self::cases(), 'value');
    }
}