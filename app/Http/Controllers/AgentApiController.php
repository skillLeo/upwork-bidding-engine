<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Agent API — the ONLY door OpenClaw's WhatsApp brain has into the
 * app's data. Deliberately narrow: read leads/clients/summary plus one
 * status POST. It must never read or write settings or API keys, never
 * delete anything, never edit proposals or rules — if a new capability
 * is ever needed, it gets its own explicit endpoint here, reviewed as
 * such, instead of widening an existing one.
 */
class AgentApiController extends Controller
{
    public function leads(Request $request): JsonResponse
    {
        $data = $request->validate([
            'score_min' => ['nullable', 'integer', 'min:1', 'max:10'],
            'status' => ['nullable', 'string', 'in:new,scoring,ready,sent,replied,won,archived,needs_review'],
            'posted_within_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $leads = Lead::query()
            ->when(isset($data['score_min']), fn ($q) => $q->where('score', '>=', $data['score_min']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(isset($data['posted_within_hours']), fn ($q) => $q->where('posted_at', '>=', now()->subHours($data['posted_within_hours'])))
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($data['limit'] ?? 10)
            ->get();

        return response()->json(['data' => $leads->map(fn (Lead $lead) => $this->leadSummary($lead))->all()]);
    }

    public function lead(Lead $lead): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->leadSummary($lead),
            'score_reason' => $lead->score_reason,
            'sub_scores' => $lead->sub_scores,
            'full_brief' => $lead->full_brief,
            'proposal_text' => $lead->proposal_text,
            'proposal_warnings' => $lead->proposal_warnings,
            'client_id' => $lead->client_id,
        ]]);
    }

    public function summary(): JsonResponse
    {
        $oldestReady = Lead::where('status', LeadStatus::Ready)->orderBy('posted_at')->first();

        return response()->json(['data' => [
            'counts_by_status' => Lead::query()
                ->selectRaw('status, count(*) as n')
                ->groupBy('status')
                ->pluck('n', 'status'),
            'new_leads_today' => Lead::whereDate('created_at', today())->count(),
            'highest_unsent_score' => Lead::where('status', LeadStatus::Ready)->max('score'),
            'oldest_ready_lead' => $oldestReady ? [
                'id' => $oldestReady->id,
                'title' => $oldestReady->title,
                'age' => $oldestReady->posted_at?->diffForHumans(),
            ] : null,
        ]]);
    }

    public function client(Client $client): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $client->id,
            'name' => $client->name,
            'stage' => $client->stage->value,
            'budget_discussed' => $client->budget_discussed,
            'agreed_scope' => $client->agreed_scope,
            'notes' => $client->notes,
            'lead_id' => $client->lead_id,
            'recent_messages' => $client->messages()->latest('created_at')->limit(10)->get()
                ->map(fn ($m) => [
                    'direction' => $m->direction->value,
                    'text' => $m->text,
                    'at' => $m->created_at->toIso8601String(),
                ])->all(),
        ]]);
    }

    public function updateStatus(Request $request, Lead $lead): JsonResponse
    {
        // sent/replied/won/archived only — the agent can advance a lead's
        // journey, never rewind it to the pipeline stages or delete it.
        $data = $request->validate(['status' => ['required', 'string', 'in:sent,replied,won,archived']]);

        $lead->update(['status' => LeadStatus::from($data['status'])]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'status' => $data['status'],
            'source' => 'agent_api',
        ]);

        return response()->json(['data' => $this->leadSummary($lead->fresh())]);
    }

    /**
     * WhatsApp-triggered re-score — the EXACT same pipeline as the
     * dashboard button (LeadRefreshService), so rules always come from
     * Settings at this second. Synchronous by design: a chat is a
     * conversation, and 10-60s is an acceptable wait for a real answer.
     * No body params are read — lead id only; rules are never accepted
     * from a caller.
     */
    public function rescore(Lead $lead, \App\Services\LeadRefreshService $refresh): JsonResponse
    {
        try {
            return response()->json(['data' => $refresh->rescore($lead, 'agent_api')]);
        } catch (\App\Services\LeadRunInProgressException $e) {
            return response()->json(['message' => 'Already in progress — a rescore or rewrite is running for this lead.'], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The run failed or timed out — check the dashboard at upwork.skillleo.com for the current state.',
            ], 500);
        }
    }

    /**
     * WhatsApp-triggered proposal rewrite — same shared pipeline as the
     * dashboard's Rewrite button, full quality gate included.
     */
    public function rewrite(Lead $lead, \App\Services\LeadRefreshService $refresh): JsonResponse
    {
        try {
            return response()->json(['data' => $refresh->rewrite($lead, 'agent_api')]);
        } catch (\App\Services\LeadRunInProgressException $e) {
            return response()->json(['message' => 'Already in progress — a rescore or rewrite is running for this lead.'], 409);
        } catch (\App\Services\Ai\ProposalWritingPausedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The run failed or timed out — check the dashboard at upwork.skillleo.com for the current state.',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function leadSummary(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'title' => $lead->title,
            'score' => $lead->score,
            'score_reason' => $lead->score_reason,
            'status' => $lead->status->value,
            'budget' => $lead->budget,
            'proposal_count' => $lead->proposal_count,
            'client_country' => $lead->client_country,
            'client_spend' => $lead->client_spend,
            'client_hire_rate' => $lead->client_hire_rate,
            'payment_verified' => (bool) $lead->payment_verified,
            'age' => $lead->posted_at?->diffForHumans(),
            'url' => $lead->url,
        ];
    }
}
