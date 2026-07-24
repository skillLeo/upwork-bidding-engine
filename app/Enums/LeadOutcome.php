<?php

namespace App\Enums;

/**
 * WHY a lead ended. `status` says HOW it ended (won / lost / archived);
 * this says why, and contains nothing status can already express.
 *
 * It used to carry `replied` and `hired_me`, which duplicated status Replied
 * and status Won and let a lead read as Won alongside "hired someone else".
 * Those are gone. What is here splits into two sets that never mix, because
 * the two branches ask genuinely different questions:
 *
 *   postBid  (status Lost)     — a proposal went out and it is over
 *   preBid   (status Archived) — no proposal ever went out
 *
 * The UI only ever offers the set matching the lead's status, so the list on
 * screen stays short even though the enum is not.
 */
enum LeadOutcome: string
{
    // --- Post-bid: Connects were spent, and it did not convert -----------
    case NoResponse = 'no_response';
    case HiredOther = 'hired_other';
    case ClosedNoHire = 'closed_no_hire';
    case Expired = 'expired';
    case Unknown = 'unknown';

    // --- Pre-bid: no proposal was ever sent ------------------------------
    case NotRelevant = 'not_relevant';
    case NoConnects = 'no_connects';
    case TooLate = 'too_late';
    /** Set by the engine, never offered as a manual choice. */
    case AutoFiltered = 'auto_filtered';

    public function label(): string
    {
        return match ($this) {
            self::NoResponse => 'No response',
            self::HiredOther => 'Client hired someone else',
            self::ClosedNoHire => 'Job closed, nobody hired',
            self::Expired => 'Posting expired',
            self::Unknown => 'Never found out',
            self::NotRelevant => 'Not relevant, skipped it',
            self::NoConnects => 'No Connects available',
            self::TooLate => 'Too late / too many proposals',
            self::AutoFiltered => 'Filtered out by the engine',
        };
    }

    /**
     * Offered when status is Lost.
     *
     * @return array<int, self>
     */
    public static function postBid(): array
    {
        return [self::NoResponse, self::HiredOther, self::ClosedNoHire, self::Expired, self::Unknown];
    }

    /**
     * Offered when status is Archived. AutoFiltered is excluded: the engine
     * writes it, a person never picks it, and offering it would invite
     * mislabelling a deliberate skip as an automatic one.
     *
     * @return array<int, self>
     */
    public static function preBid(): array
    {
        return [self::NotRelevant, self::NoConnects, self::TooLate];
    }

    /**
     * "This job was never winnable by anyone", so it must not count against
     * the reply rate. HiredOther and NoResponse are excluded on purpose:
     * someone else won, or the client was active and ignored you. Both are
     * real losses and should still count.
     *
     * @return array<int, string>
     */
    public static function deadEndValues(): array
    {
        return [self::ClosedNoHire->value, self::Expired->value, self::Unknown->value];
    }

    /**
     * The reasons valid for a given status. Anything else is rejected at the
     * API, so a lead can never carry a reason that contradicts where it is.
     *
     * @return array<int, string>
     */
    public static function valuesForStatus(LeadStatus $status): array
    {
        return match ($status) {
            LeadStatus::Lost => array_map(fn (self $o) => $o->value, self::postBid()),
            LeadStatus::Archived => array_map(fn (self $o) => $o->value, self::preBid()),
            // Still live, or won. Nothing to explain yet - the only legal
            // write is clearing the field back to null.
            default => [],
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
