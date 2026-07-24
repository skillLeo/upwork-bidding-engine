<?php

namespace App\Enums;

/**
 * Why a sent proposal did NOT land — and nothing else.
 *
 * `status` already records the good news: Sent -> Replied -> Won. This enum
 * deliberately contains NO value that `status` can already express. It used
 * to carry `replied` and `hired_me`, which duplicated status Replied and
 * status Won; that let a lead be status=Won and outcome=hired_other at the
 * same time, a contradiction the UI happily accepted. The duplicates are
 * gone, so the two fields can no longer disagree.
 *
 * What remains is the thing status genuinely cannot say: a large share of
 * Upwork jobs never hire anyone at all. Without that distinction the reply
 * rate silently counts every dead posting as a personal failure. Recording
 * ClosedNoHire / Expired / Unknown is what removes a lead from the
 * "contested" denominator in AnalyticsService.
 */
enum LeadOutcome: string
{
    case HiredOther = 'hired_other';
    case ClosedNoHire = 'closed_no_hire';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::HiredOther => 'Client hired someone else',
            self::ClosedNoHire => 'Job closed, nobody hired',
            self::Expired => 'Posting expired',
            self::Unknown => 'Never found out',
        };
    }

    /**
     * The subset meaning "this job was never winnable by anyone", so it must
     * not count against the reply rate. HiredOther is excluded on purpose:
     * someone else won it, which is a real loss and should still count.
     *
     * @return array<int, string>
     */
    public static function deadEndValues(): array
    {
        return [self::ClosedNoHire->value, self::Expired->value, self::Unknown->value];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
