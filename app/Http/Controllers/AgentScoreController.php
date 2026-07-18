<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\Ai\ScoringService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One brain, no drift: OpenClaw (WhatsApp chat "score this brief for me")
 * calls THIS endpoint instead of scoring with its own prompt, so every
 * score — webhook pipeline or ad-hoc chat — comes from the same rubric in
 * Settings. Authenticated with the shared OpenClaw bearer token.
 */
class AgentScoreController extends Controller
{
    public function __invoke(Request $request, SettingsService $settings, ScoringService $scoring): JsonResponse
    {
        $expected = (string) $settings->openClawToken();

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid or missing token.'], 401);
        }

        $data = $request->validate([
            'brief' => ['required', 'string', 'max:20000'],
            'title' => ['nullable', 'string', 'max:500'],
            'budget' => ['nullable', 'string', 'max:100'],
            'proposal_count' => ['nullable', 'integer', 'min:0'],
            'connects_required' => ['nullable', 'integer', 'min:0'],
            'client_country' => ['nullable', 'string', 'max:100'],
            'client_spend' => ['nullable', 'string', 'max:50'],
            'client_hire_rate' => ['nullable', 'string', 'max:20'],
            'payment_verified' => ['nullable', 'boolean'],
        ]);

        // A transient, never-saved Lead: reuses the exact same jobContent
        // builder the pipeline uses, so the model sees identical input.
        $lead = new Lead([
            'title' => $data['title'] ?? '(untitled brief)',
            'full_brief' => $data['brief'],
            'budget' => $data['budget'] ?? null,
            'proposal_count' => $data['proposal_count'] ?? 0,
            'connects_required' => $data['connects_required'] ?? null,
            'client_country' => $data['client_country'] ?? null,
            'client_spend' => $data['client_spend'] ?? null,
            'client_hire_rate' => $data['client_hire_rate'] ?? null,
            'payment_verified' => (bool) ($data['payment_verified'] ?? false),
        ]);

        $result = $scoring->score($lead);

        return response()->json(['data' => [
            'score' => $result['score'],
            'bid' => $result['bid'],
            'boost' => $result['boost'],
            'reason' => $result['reason'],
            'sub_scores' => $result['sub_scores'],
        ]]);
    }
}
