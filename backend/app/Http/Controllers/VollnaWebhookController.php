<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Http\Requests\Webhooks\VollnaWebhookRequest;
use App\Jobs\ScoreLeadJob;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class VollnaWebhookController extends Controller
{
    public function __invoke(VollnaWebhookRequest $request): JsonResponse
    {
        $payload = $request->all();
        $mapped = $this->mapPayload($payload);

        if ($mapped['external_id'] === '' || $mapped['title'] === '') {
            return response()->json(['message' => 'Payload is missing a usable job identifier or title.'], 422);
        }

        $existing = Lead::query()->where('external_id', $mapped['external_id'])->first();

        if ($existing) {
            ActivityLog::record(ActivityType::LeadDuplicateSkipped, subject: $existing, meta: [
                'external_id' => $mapped['external_id'],
            ]);

            // Idempotent: Vollna may retry deliveries, this must not create dupes or re-score.
            return response()->json(['data' => ['status' => 'duplicate', 'lead_id' => $existing->id]]);
        }

        $lead = Lead::create([...$mapped, 'status' => LeadStatus::New]);

        ActivityLog::record(ActivityType::LeadReceived, subject: $lead, meta: ['source' => 'vollna']);

        ScoreLeadJob::dispatch($lead->id);

        return response()->json(['data' => ['status' => 'accepted', 'lead_id' => $lead->id]], 201);
    }

    /**
     * Vollna's exact field names aren't a contract we control, so this maps
     * defensively across a few plausible aliases instead of trusting one
     * exact shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mapPayload(array $payload): array
    {
        $client = (array) Arr::get($payload, 'client', []);

        $externalId = Arr::get($payload, 'external_id')
            ?? Arr::get($payload, 'id')
            ?? Arr::get($payload, 'job_id')
            ?? Arr::get($payload, 'uid');

        $title = trim((string) Arr::get($payload, 'title', ''));

        if (! $externalId && $title !== '') {
            // Last-resort stable id so a malformed-but-real payload still dedupes on retry.
            $externalId = 'vollna_'.md5($title.'|'.Arr::get($payload, 'url', ''));
        }

        return [
            'external_id' => (string) ($externalId ?? ''),
            'title' => $title,
            'full_brief' => (string) (
                Arr::get($payload, 'description')
                ?? Arr::get($payload, 'brief')
                ?? Arr::get($payload, 'full_brief')
                ?? ''
            ),
            'url' => Arr::get($payload, 'url') ?? Arr::get($payload, 'link'),
            'budget' => $this->normalizeBudget($payload),
            'client_country' => Arr::get($client, 'country') ?? Arr::get($payload, 'client_country'),
            'client_spend' => $this->stringifyMoney(
                Arr::get($client, 'totalSpent') ?? Arr::get($client, 'total_spent') ?? Arr::get($payload, 'client_spend')
            ),
            'client_hire_rate' => $this->stringifyPercent(
                Arr::get($client, 'hireRate') ?? Arr::get($client, 'hire_rate') ?? Arr::get($payload, 'client_hire_rate')
            ),
            'payment_verified' => (bool) (
                Arr::get($client, 'paymentVerified') ?? Arr::get($client, 'payment_verified') ?? Arr::get($payload, 'payment_verified') ?? false
            ),
            'proposal_count' => (int) (
                Arr::get($payload, 'proposals') ?? Arr::get($payload, 'proposal_count') ?? Arr::get($payload, 'applicants') ?? 0
            ),
            'posted_at' => $this->parseDate(
                Arr::get($payload, 'postedOn') ?? Arr::get($payload, 'posted_at') ?? Arr::get($payload, 'publishedDateTime')
            ),
        ];
    }

    protected function normalizeBudget(array $payload): ?string
    {
        $budget = Arr::get($payload, 'budget');

        if (is_string($budget) && $budget !== '') {
            return $budget;
        }

        if (is_numeric($budget)) {
            return '$'.number_format((float) $budget, 0).' fixed';
        }

        if (is_array($budget)) {
            $type = Arr::get($budget, 'type');

            if (isset($budget['amount'])) {
                $amount = '$'.number_format((float) $budget['amount'], 0);

                return $type === 'hourly' ? $amount.'/hr' : $amount.' fixed';
            }

            $min = Arr::get($budget, 'minimum');
            $max = Arr::get($budget, 'maximum');

            if ($min || $max) {
                $range = ($min && $max) ? '$'.$min.'-$'.$max : '$'.($min ?? $max);

                return $range.'/hr';
            }
        }

        return null;
    }

    protected function stringifyMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $amount = (float) $value;

            return $amount >= 1000
                ? '$'.number_format($amount / 1000, 1).'K'
                : '$'.number_format($amount, 0);
        }

        return (string) $value;
    }

    protected function stringifyPercent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && Str::contains($value, '%')) {
            return $value;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $percent = $number <= 1 ? $number * 100 : $number;

            return round($percent).'%';
        }

        return (string) $value;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }
}
