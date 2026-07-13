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

        if ($include = $this->queryList($request, 'include_keywords')) {
            $query->where(function ($q) use ($include) {
                foreach ($include as $keyword) {
                    $q->orWhere('title', 'like', "%{$keyword}%")
                        ->orWhere('full_brief', 'like', "%{$keyword}%");
                }
            });
        }

        if ($exclude = $this->queryList($request, 'exclude_keywords')) {
            $query->where(function ($q) use ($exclude) {
                foreach ($exclude as $keyword) {
                    $q->where('title', 'not like', "%{$keyword}%")
                        ->where('full_brief', 'not like', "%{$keyword}%");
                }
            });
        }

        if ($request->filled('budget_min')) {
            // A lead with no parsed budget can't be excluded by a floor it
            // never reported meeting — only leads with a KNOWN budget below
            // the floor get filtered out.
            $query->where(function ($q) use ($request) {
                $q->whereNull('budget_max')->orWhere('budget_max', '>=', (float) $request->query('budget_min'));
            });
        }

        if ($request->filled('budget_max')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('budget_min')->orWhere('budget_min', '<=', (float) $request->query('budget_max'));
            });
        }

        if ($request->boolean('payment_verified_only')) {
            $query->where('payment_verified', true);
        }

        if ($request->filled('min_client_spend')) {
            $query->where('client_spend_amount', '>=', (float) $request->query('min_client_spend'));
        }

        if ($countriesIn = $this->queryList($request, 'client_countries_include')) {
            $query->whereIn('client_country', $countriesIn);
        }

        if ($countriesOut = $this->queryList($request, 'client_countries_exclude')) {
            $query->whereNotIn('client_country', $countriesOut);
        }

        if ($request->filled('posted_within_minutes')) {
            $query->where('posted_at', '>=', now()->subMinutes((int) $request->query('posted_within_minutes')));
        }

        $sort = (string) $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, ['created_at', 'score', 'posted_at', 'proposal_count', 'budget_max'], true)) {
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

    /**
     * Saved filter criteria arrays arrive as either `key[]=a&key[]=b` or a
     * single comma-separated `key=a,b` (simpler to build client-side) -
     * accept both instead of forcing the frontend into one array format.
     *
     * @return array<int, string>
     */
    protected function queryList(Request $request, string $key): array
    {
        $value = $request->query($key);

        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
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
