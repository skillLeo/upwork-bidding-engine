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
    // Bid, and it is over without a contract. Terminal, and deliberately
    // separate from Archived: Lost means a proposal WAS sent (so it still
    // counts in every reply/win-rate denominator and is never pruned),
    // Archived means one never was. LeadOutcome records which one it is.
    case Lost = 'lost';
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
            self::Lost => 'Lost',
            self::Archived => 'Archived',
        };
    }

    /**
     * Every status that means "a proposal actually went out". These are the
     * denominators for reply rate, win rate and calibration, and the set
     * PruneOldLeadsCommand must never touch - once Connects were spent, the
     * lead is a permanent record.
     *
     * @return array<int, self>
     */
    public static function sentOrBeyond(): array
    {
        return [self::Sent, self::Replied, self::Won, self::Lost];
    }

    /**
     * Ended, one way or another. Nothing further is expected to happen.
     *
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return [self::Won, self::Lost, self::Archived];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
