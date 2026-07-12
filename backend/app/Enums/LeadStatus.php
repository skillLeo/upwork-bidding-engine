<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Scoring = 'scoring';
    case Ready = 'ready';
    case Sent = 'sent';
    case Replied = 'replied';
    case Won = 'won';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Scoring => 'Scoring',
            self::Ready => 'Ready',
            self::Sent => 'Sent',
            self::Replied => 'Replied',
            self::Won => 'Won',
            self::Archived => 'Archived',
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
