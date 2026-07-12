<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\ClientStage;
use App\Enums\LeadStatus;
use App\Http\Requests\Leads\UpdateLeadStatusRequest;
use App\Http\Resources\LeadResource;
use App\Jobs\ScoreLeadJob;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()->with('client');

        if ($status = $request->query('status')) {
            $statuses = array_values(array_filter(explode(',', (string) $status)));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('score_min')) {
            $query->where('score', '>=', (int) $request->query('score_min'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('full_brief', 'like', "%{$search}%");
            });
        }

        $sort = (string) $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, ['created_at', 'score', 'posted_at', 'proposal_count'], true)) {
            $column = 'created_at';
        }

        $query->orderBy($column, $direction)->orderBy('id', 'desc');

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $leads = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => LeadResource::collection($leads->items()),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
            ],
        ]);
    }

    public function show(Lead $lead): JsonResponse
    {
        return response()->json(['data' => new LeadResource($lead->load('client'))]);
    }

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead): JsonResponse
    {
        $oldStatus = $lead->status;
        $newStatus = LeadStatus::from($request->validated('status'));

        $lead->update(['status' => $newStatus]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'from' => $oldStatus->value,
            'to' => $newStatus->value,
        ], userId: $request->user()?->id);

        if ($newStatus === LeadStatus::Sent) {
            ActivityLog::record(ActivityType::ProposalSent, subject: $lead, userId: $request->user()?->id);
        }

        // First forward transition past "ready" is what turns a lead into a real
        // client relationship — provision the Client record here if missing so
        // Client Memory has somewhere to attach the conversation.
        if (! $lead->client_id && in_array($newStatus, [LeadStatus::Sent, LeadStatus::Replied, LeadStatus::Won], true)) {
            $client = Client::create([
                'name' => $lead->title,
                'lead_id' => $lead->id,
                'budget_discussed' => $lead->budget,
                'stage' => ClientStage::New,
            ]);

            $lead->update(['client_id' => $client->id]);
        }

        return response()->json(['data' => new LeadResource($lead->fresh('client'))]);
    }

    public function rescore(Lead $lead): JsonResponse
    {
        $lead->update([
            'status' => LeadStatus::New,
            'score' => null,
            'score_reason' => null,
            'proposal_text' => null,
        ]);

        ScoreLeadJob::dispatch($lead->id);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: ['action' => 'rescore_requested']);

        return response()->json(['data' => new LeadResource($lead->fresh())]);
    }
}
