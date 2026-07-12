<?php

namespace App\Enums;

enum ClientStage: string
{
    case New = 'new';
    case Talking = 'talking';
    case Negotiating = 'negotiating';
    case Closing = 'closing';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Talking => 'Talking',
            self::Negotiating => 'Negotiating',
            self::Closing => 'Closing',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
