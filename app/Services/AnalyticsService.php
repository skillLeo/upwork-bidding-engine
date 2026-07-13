<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
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

        return [
            'total_leads' => array_sum($byStatus),
            'by_status' => $byStatus,
            'proposals_sent' => $sent,
            'reply_rate' => $sent > 0 ? round(($repliedOrWon / $sent) * 100, 1) : 0.0,
            'win_rate' => $sent > 0 ? round(($won / $sent) * 100, 1) : 0.0,
            'avg_score' => round($avgScore, 1),
            // Upwork "Connects" cost per proposal varies by job and isn't in the
            // Vollna payload, so this is a clearly-labeled estimate, not tracked fact.
            'estimated_connects_spent' => $sent * 4,
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
        $keywords = $this->settings->get('stack_keywords', []);
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
        $rows = Lead::query()
            ->whereNotNull('posted_at')
            ->selectRaw('HOUR(posted_at) as hour, count(*) as total')
            ->groupBy('hour')
            ->pluck('total', 'hour');

        $out = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $out[] = ['hour' => $hour, 'count' => (int) ($rows[$hour] ?? 0)];
        }

        return $out;
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
