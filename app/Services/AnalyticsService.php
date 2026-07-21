<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    // Below this many sent leads at a given score, the rate is noise, not signal.
    public const LOW_CONFIDENCE_THRESHOLD = 5;

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

        $sent = $byStatus['sent'] + $byStatus['replied'] + $byStatus['won'];
        $repliedOrWon = $byStatus['replied'] + $byStatus['won'];
        $won = $byStatus['won'];

        $avgScore = (float) (Lead::query()->whereNotNull('score')->avg('score') ?? 0);

        $sentStatuses = [LeadStatus::Sent, LeadStatus::Replied, LeadStatus::Won];
        $knownConnects = (int) (Lead::query()->whereIn('status', $sentStatuses)->sum('connects_required'));
        $sentWithoutKnownConnects = Lead::query()->whereIn('status', $sentStatuses)->whereNull('connects_required')->count();

        return [
            'total_leads' => array_sum($byStatus),
            'by_status' => $byStatus,
            'proposals_sent' => $sent,
            'reply_rate' => $sent > 0 ? round(($repliedOrWon / $sent) * 100, 1) : 0.0,
            'win_rate' => $sent > 0 ? round(($won / $sent) * 100, 1) : 0.0,
            'avg_score' => round($avgScore, 1),
            // Real connects_required (from Vollna) for leads that have it,
            // plus a clearly-labeled 4-per-proposal estimate only for the
            // remainder that don't - not a pure guess once real data exists.
            'estimated_connects_spent' => $knownConnects + ($sentWithoutKnownConnects * 4),
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
        $sentStatuses = [LeadStatus::Sent->value, LeadStatus::Replied->value, LeadStatus::Won->value];
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
