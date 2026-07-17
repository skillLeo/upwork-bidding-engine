<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Scoring = 'scoring';
    // AI scoring failed after all retries — deliberately NOT Archived,
    // which reads as "we evaluated this and passed". Needs-review leads
    // stay visible and are eligible for a manual rescore.
    case NeedsReview = 'needs_review';
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
            self::NeedsReview => 'Needs review',
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
