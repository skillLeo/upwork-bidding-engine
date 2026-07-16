<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
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

        return response()->json(['data' => [
            'queue_depth' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'ai_engine_enabled' => $settings->aiEngineEnabled(),
            'openclaw_online' => $openClaw->isReachable(),
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
