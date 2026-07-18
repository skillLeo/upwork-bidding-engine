<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A single "why is nothing happening" screen — queue depth, failed jobs,
 * the last time anything actually scored or arrived, and OpenClaw's live
 * status, so a stuck pipeline is visible without reading server logs.
 */
class DiagnosticsController extends Controller
{
    public function __invoke(SettingsService $settings, OpenClawService $openClaw): JsonResponse
    {
        $lastScored = ActivityLog::where('type', ActivityType::LeadScored->value)
            ->latest('id')->first();

        $lastError = ActivityLog::whereIn('type', [
            ActivityType::LeadScoringFailed->value,
            ActivityType::BidderNotifyFailed->value,
        ])->latest('id')->first();

        $lastWebhookReceived = ActivityLog::where('type', ActivityType::LeadReceived->value)
            ->latest('id')->first();

        $lastWebhookRejected = ActivityLog::where('type', ActivityType::WebhookRejected->value)
            ->latest('id')->first();

        // Avg over today's scored leads, from the duration_ms each scoring
        // call now records. PHP-side because meta is a JSON column.
        $scoredToday = ActivityLog::where('type', ActivityType::LeadScored->value)
            ->whereDate('created_at', today())
            ->get(['meta']);
        $durations = $scoredToday->pluck('meta.duration_ms')->filter(fn ($ms) => is_numeric($ms));

        $lastWebhookStamp = $settings->get('vollna_last_webhook_at');
        $silenceThresholdHours = max(1, (int) $settings->get('vollna_silence_alert_hours', 6));

        return response()->json(['data' => [
            // Stamped by the scheduler every minute; read here on a web
            // request so a dead cron is visible even though the scheduler
            // can't alert about its own death.
            'cron_last_tick' => \Illuminate\Support\Facades\Cache::get('cron:last_tick'),
            'queue_depth' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'ai_engine_enabled' => $settings->aiEngineEnabled(),
            'openclaw_online' => $openClaw->isReachable(),
            'whatsapp' => $openClaw->whatsappStatus(),
            'vollna_last_webhook_at' => $lastWebhookStamp,
            'vollna_silence_threshold_hours' => $silenceThresholdHours,
            'leads_today' => Lead::whereDate('created_at', today())->count(),
            'leads_scored_today' => $scoredToday->count(),
            'avg_scoring_ms' => $durations->isNotEmpty() ? (int) round($durations->avg()) : null,
            'last_scored_at' => $lastScored?->created_at?->toIso8601String(),
            'last_error' => $lastError ? [
                'type' => $lastError->type,
                'message' => $lastError->meta['error'] ?? null,
                'at' => $lastError->created_at->toIso8601String(),
            ] : null,
            'last_webhook_received_at' => $lastWebhookReceived?->created_at?->toIso8601String(),
            'last_webhook_rejected' => $lastWebhookRejected ? [
                'reason' => $lastWebhookRejected->meta['reason'] ?? null,
                'at' => $lastWebhookRejected->created_at->toIso8601String(),
            ] : null,
        ]]);
    }
}
