<?php

namespace App\Enums;

/**
 * A finer-grained classification than `status` for what actually happened
 * after a proposal was sent. `status` (Sent -> Replied -> Won) drives the
 * dashboard's pipeline/filters and stays exactly as it is; `outcome` exists
 * specifically to distinguish "a real loss" from "nobody was ever hired" -
 * a large share of Upwork jobs never hire anyone, and without this the reply
 * rate silently counts every one of those as a personal failure.
 *
 * Deliberately independent of `status`: setting an outcome never changes the
 * lead's status as a side effect (and vice versa) - two separate records of
 * two separate questions ("where is this in my pipeline" vs "what actually
 * happened"), each editable on its own.
 */
enum LeadOutcome: string
{
    case Replied = 'replied';
    case HiredMe = 'hired_me';
    case HiredOther = 'hired_other';
    case ClosedNoHire = 'closed_no_hire';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Replied => 'Replied',
            self::HiredMe => 'Hired me',
            self::HiredOther => 'Hired someone else',
            self::ClosedNoHire => 'Closed, nobody hired',
            self::Expired => 'Expired',
            self::Unknown => 'Unknown',
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
