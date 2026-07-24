<?php

namespace App\Services;

use App\Enums\ClientView;
use App\Enums\LeadOutcome;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    // Below this many sent leads at a given score, the rate is noise, not signal.
    public const LOW_CONFIDENCE_THRESHOLD = 5;

    /** Below this many leads in a rate's own denominator, render "not enough
     * data" instead of a percentage - applies to every NEW rate this class
     * reports (reply_rate_raw/contested, speed). Pre-existing fields
     * (reply_rate, win_rate) are left exactly as they were. */
    public const MIN_SAMPLE_FOR_RATE = 5;

    public function __construct(protected SettingsService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $counts = Lead::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byStatus = [];
        foreach (LeadStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        // Lost counts here: Connects were spent and a proposal went out, so
        // it belongs in every "of the proposals I sent" denominator. Leaving
        // it out would quietly inflate the reply and win rates as soon as
        // dead leads start being closed off honestly.
        $sent = array_sum(array_map(fn (LeadStatus $s) => $byStatus[$s->value], LeadStatus::sentOrBeyond()));
        $repliedOrWon = $byStatus['replied'] + $byStatus['won'];
        $won = $byStatus['won'];

        $avgScore = (float) (Lead::query()->whereNotNull('score')->avg('score') ?? 0);

        $sentStatuses = LeadStatus::sentOrBeyond();
        $knownConnects = (int) (Lead::query()->whereIn('status', $sentStatuses)->sum('connects_required'));
        $sentWithoutKnownConnects = Lead::query()->whereIn('status', $sentStatuses)->whereNull('connects_required')->count();

        // A large share of Upwork jobs never hire anyone at all - counting
        // every one of those as a personal loss (which the plain reply_rate
        // above does) understates real performance. "Contested" narrows the
        // denominator to leads whose outcome we know did NOT end in one of
        // those no-fault dead ends. A lead with no outcome recorded yet is
        // still counted here (we don't yet know it's a dead end, so it isn't
        // excluded) - only a CONFIRMED dead-end outcome removes a lead from
        // this denominator. The numerator (replied+won) is unchanged and is
        // already a subset of "contested" by definition - a lead can't be
        // status Replied/Won while also being a confirmed no-reply dead end.
        $deadEndOutcomes = LeadOutcome::deadEndValues();
        $contestedSent = Lead::query()->whereIn('status', $sentStatuses)
            ->where(fn ($q) => $q->whereNull('outcome')->orWhereNotIn('outcome', $deadEndOutcomes))
            ->count();

        return [
            'total_leads' => array_sum($byStatus),
            'by_status' => $byStatus,
            'proposals_sent' => $sent,
            'reply_rate' => $sent > 0 ? round(($repliedOrWon / $sent) * 100, 1) : 0.0,
            'win_rate' => $sent > 0 ? round(($won / $sent) * 100, 1) : 0.0,
            'reply_rate_raw' => [
                'n' => $sent,
                'rate' => $sent >= self::MIN_SAMPLE_FOR_RATE ? round(($repliedOrWon / $sent) * 100, 1) : null,
            ],
            'reply_rate_contested' => [
                'n' => $contestedSent,
                'rate' => $contestedSent >= self::MIN_SAMPLE_FOR_RATE ? round(($repliedOrWon / $contestedSent) * 100, 1) : null,
            ],
            'avg_score' => round($avgScore, 1),
            // Real connects_required (from Vollna) for leads that have it,
            // plus a clearly-labeled 4-per-proposal estimate only for the
            // remainder that don't - not a pure guess once real data exists.
            'estimated_connects_spent' => $knownConnects + ($sentWithoutKnownConnects * 4),
        ];
    }

    /**
     * Median and p90 minutes from a job posting to us actually submitting -
     * the number the whole "freshness wins" architecture has never measured.
     * Windowed on submitted_at (was this proposal submitted in the last 30
     * days), not posted_at.
     *
     * @return array{n: int, median_minutes: ?int, p90_minutes: ?int}
     */
    public function speed(): array
    {
        $minutes = Lead::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('posted_at')
            ->where('submitted_at', '>=', now()->subDays(30))
            ->get(['posted_at', 'submitted_at'])
            ->map(fn (Lead $lead) => $lead->posted_at->diffInMinutes($lead->submitted_at))
            ->sort()
            ->values();

        $n = $minutes->count();
        $percentile = fn (float $p) => $minutes[min((int) floor($n * $p), $n - 1)];

        return [
            'n' => $n,
            'median_minutes' => $n >= self::MIN_SAMPLE_FOR_RATE ? $percentile(0.5) : null,
            'p90_minutes' => $n >= self::MIN_SAMPLE_FOR_RATE ? $percentile(0.9) : null,
        ];
    }

    /**
     * Splits sent proposals by how far they got on the client's side, so a
     * low reply rate points at a specific fix rather than a vague one:
     *
     *   not_opened           -> the title, the opening line, or the profile
     *   opened_no_reply      -> the letter body and the closing question
     *   shortlisted_no_reply -> you were in the running; price, portfolio
     *                           depth, or a missing follow-up
     *   replied              -> it landed
     *
     * client_view is filled in by hand off Upwork, so it is often partly
     * complete. NULL means "not recorded", NOT "the client never opened it";
     * counting untouched leads as not-opened would make a half-filled field
     * read as a catastrophic title problem. Those leads are reported
     * separately in `not_recorded` and excluded from every bucket above, so
     * partial data gives a usable signal instead of a misleading one.
     *
     * `replied` is keyed off status, not client_view: a client who replied
     * demonstrably opened it, so that is a fact rather than a guess, and it
     * counts even when client_view was never touched.
     *
     * @return array{not_opened: int, opened_no_reply: int, shortlisted_no_reply: int, replied: int, not_recorded: int, recorded: int, total_sent: int}
     */
    public function dyingProposals(): array
    {
        $sentStatuses = array_column(LeadStatus::sentOrBeyond(), 'value');
        $repliedStatuses = [LeadStatus::Replied->value, LeadStatus::Won->value];

        $base = fn () => Lead::query()->whereIn('status', $sentStatuses);

        // Awaiting a reply AND the client-side state was actually recorded.
        $pending = fn () => Lead::query()
            ->where('status', LeadStatus::Sent->value)
            ->whereNotNull('client_view');

        $replied = $base()->whereIn('status', $repliedStatuses)->count();
        $notOpened = $pending()->where('client_view', ClientView::NotViewed->value)->count();
        $openedNoReply = $pending()->where('client_view', ClientView::Viewed->value)->count();
        $shortlistedNoReply = $pending()->where('client_view', ClientView::Shortlisted->value)->count();

        $notRecorded = $base()->where('status', LeadStatus::Sent->value)->whereNull('client_view')->count();

        return [
            'not_opened' => $notOpened,
            'opened_no_reply' => $openedNoReply,
            'shortlisted_no_reply' => $shortlistedNoReply,
            'replied' => $replied,
            // Surfaced, not hidden: if this dwarfs the buckets above, the
            // panel is reading a field nobody is filling in and should be
            // treated as noise until it is.
            'not_recorded' => $notRecorded,
            'recorded' => $notOpened + $openedNoReply + $shortlistedNoReply + $replied,
            'total_sent' => $base()->count(),
        ];
    }

    /**
     * @return array<int, array{date: string, received: int, sent: int, replied: int, won: int}>
     */
    public function trend(int $days = 14): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $received = Lead::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, count(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $statusEvents = ActivityLog::query()
            ->where('type', 'lead_status_updated')
            ->where('created_at', '>=', $since)
            ->get(['meta', 'created_at']);

        $sentByDate = [];
        $repliedByDate = [];
        $wonByDate = [];

        foreach ($statusEvents as $event) {
            $date = $event->created_at->toDateString();
            $to = $event->meta['to'] ?? null;

            match ($to) {
                'sent' => $sentByDate[$date] = ($sentByDate[$date] ?? 0) + 1,
                'replied' => $repliedByDate[$date] = ($repliedByDate[$date] ?? 0) + 1,
                'won' => $wonByDate[$date] = ($wonByDate[$date] ?? 0) + 1,
                default => null,
            };
        }

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();

            $out[] = [
                'date' => $date,
                'received' => (int) ($received[$date] ?? 0),
                'sent' => (int) ($sentByDate[$date] ?? 0),
                'replied' => (int) ($repliedByDate[$date] ?? 0),
                'won' => (int) ($wonByDate[$date] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{keyword: string, count: int, won: int}>
     */
    public function bestJobTypes(): array
    {
        // Core + secondary = the stacks actually worked in, which is what the
        // "best job types" chart should reflect (excluded stacks are noise).
        $stacks = $this->settings->stackLists();
        $keywords = array_values(array_unique(array_merge($stacks['core'], $stacks['secondary'])));
        $rows = [];

        foreach ((array) $keywords as $keyword) {
            $base = Lead::query()->where('title', 'like', "%{$keyword}%");

            $count = (clone $base)->count();

            if ($count === 0) {
                continue;
            }

            $won = (clone $base)->where('status', LeadStatus::Won->value)->count();

            $rows[] = ['keyword' => $keyword, 'count' => $count, 'won' => $won];
        }

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 8);
    }

    /**
     * @return array<int, array{hour: int, count: int}>
     */
    public function bestHours(): array
    {
        // Counted in PHP rather than SQL HOUR() - that function is
        // MySQL-only and isn't portable to the sqlite test database.
        $rows = Lead::query()
            ->whereNotNull('posted_at')
            ->pluck('posted_at')
            ->countBy(fn ($postedAt) => $postedAt->hour);

        $out = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $out[] = ['hour' => $hour, 'count' => (int) ($rows[$hour] ?? 0)];
        }

        return $out;
    }

    /**
     * Reply/win rate segmented by score, so the rubric's own 1-10 granularity
     * can be checked against real outcomes instead of assumed to be signal.
     *
     * @return array{low_confidence_threshold: int, rows: array<int, array{score: int, sent_count: int, reply_count: int, win_count: int, reply_rate: float, win_rate: float}>}
     */
    public function scoreCalibration(): array
    {
        $sentStatuses = array_column(LeadStatus::sentOrBeyond(), 'value');
        $repliedStatuses = [LeadStatus::Replied->value, LeadStatus::Won->value];

        $rows = Lead::query()
            ->whereNotNull('score')
            ->whereIn('status', $sentStatuses)
            ->selectRaw(
                'score, count(*) as sent_count,'
                .' sum(case when status in (?, ?) then 1 else 0 end) as reply_count,'
                .' sum(case when status = ? then 1 else 0 end) as win_count',
                [...$repliedStatuses, LeadStatus::Won->value]
            )
            ->groupBy('score')
            ->orderBy('score')
            ->get()
            ->map(function ($row) {
                $sentCount = (int) $row->sent_count;
                $replyCount = (int) $row->reply_count;
                $winCount = (int) $row->win_count;

                return [
                    'score' => (int) $row->score,
                    'sent_count' => $sentCount,
                    'reply_count' => $replyCount,
                    'win_count' => $winCount,
                    'reply_rate' => $sentCount > 0 ? round(($replyCount / $sentCount) * 100, 1) : 0.0,
                    'win_rate' => $sentCount > 0 ? round(($winCount / $sentCount) * 100, 1) : 0.0,
                ];
            })
            ->values()
            ->all();

        return [
            'low_confidence_threshold' => self::LOW_CONFIDENCE_THRESHOLD,
            'rows' => $rows,
        ];
    }

    /**
     * Reply and win rate segmented BY BIDDER — the screen that tells an
     * agency which of its people actually converts, keyed on the
     * submitted_by_user_id stamped when a lead first went to Sent.
     *
     * Rates below MIN_SAMPLE_FOR_RATE sent leads render null ("not enough
     * data"), consistent with every other rate this class reports — a 100%
     * win rate on one bid is noise, not a leaderboard.
     *
     * @return array<int, array{user_id: ?int, name: string, sent: int, reply_rate: ?float, win_rate: ?float}>
     */
    public function byBidder(): array
    {
        $sentStatuses = array_column(LeadStatus::sentOrBeyond(), 'value');
        $repliedStatuses = [LeadStatus::Replied->value, LeadStatus::Won->value];

        return Lead::query()
            ->leftJoin('users', 'users.id', '=', 'leads.submitted_by_user_id')
            ->whereIn('leads.status', $sentStatuses)
            ->groupBy('leads.submitted_by_user_id', 'users.name')
            ->selectRaw(
                'leads.submitted_by_user_id as user_id,'
                .' users.name as name,'
                .' count(*) as sent,'
                .' sum(case when leads.status in (?, ?) then 1 else 0 end) as reply_count,'
                .' sum(case when leads.status = ? then 1 else 0 end) as win_count',
                [...$repliedStatuses, LeadStatus::Won->value]
            )
            ->orderByDesc('sent')
            ->get()
            ->map(function ($row) {
                $sent = (int) $row->sent;
                $enough = $sent >= self::MIN_SAMPLE_FOR_RATE;

                return [
                    'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                    // Leads sent before attribution existed have no submitter.
                    'name' => $row->name ?? 'Unattributed',
                    'sent' => $sent,
                    'reply_rate' => $enough ? round(((int) $row->reply_count / $sent) * 100, 1) : null,
                    'win_rate' => $enough ? round(((int) $row->win_count / $sent) * 100, 1) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentActivity(int $limit = 20): array
    {
        return ActivityLog::query()
            ->with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'type' => $log->type,
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'meta' => $log->meta,
                'user' => $log->user?->name,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->all();
    }
}
