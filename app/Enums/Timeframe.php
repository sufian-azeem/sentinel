<?php

namespace App\Enums;

enum Timeframe: string
{
    case M5 = '5M';
    case M15 = '15M';
    case M30 = '30M';
    case H1 = '1H';
    case H2 = '2H';
    case H4 = '4H';
    case H8 = '8H';
    case H12 = '12H';
    case D1 = '1D';
    case W1 = '1W';

    public function tvInterval(): string
    {
        return match ($this) {
            self::M5 => '5',
            self::M15 => '15',
            self::M30 => '30',
            self::H1 => '60',
            self::H2 => '120',
            self::H4 => '240',
            self::H8 => '480',
            self::H12 => '720',
            self::D1 => 'D',
            self::W1 => 'W',
        };
    }
}
