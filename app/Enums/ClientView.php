<?php

namespace App\Enums;

/**
 * How far a submitted proposal got on the CLIENT's side, recorded by hand.
 *
 * This app has no connection to Upwork and never will (browser automation
 * against Upwork is a hard rule: never). Upwork does surface a per-proposal
 * status to the freelancer, but exposes no API for it, so this is a manual
 * field: you read it on Upwork, then set it here.
 *
 * NULL is a real, distinct value meaning "not recorded yet" — NOT "the
 * client never opened it". Keeping those two apart is the whole point:
 * treating an untouched lead as "never opened" would make a half-filled
 * field read as a catastrophic title problem, which is worse than having
 * no data at all. AnalyticsService::dyingProposals() excludes NULL from
 * its denominator for exactly this reason.
 *
 * Shortlisted is deliberately separate from Viewed. "Opened it and moved
 * on" and "put me on the shortlist and still went quiet" are different
 * failures with different fixes — the first is the letter, the second is
 * usually price, portfolio depth, or a missing follow-up.
 */
enum ClientView: string
{
    case NotViewed = 'not_viewed';
    case Viewed = 'viewed';
    case Shortlisted = 'shortlisted';

    public function label(): string
    {
        return match ($this) {
            self::NotViewed => 'Not opened',
            self::Viewed => 'Opened',
            self::Shortlisted => 'Shortlisted',
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
