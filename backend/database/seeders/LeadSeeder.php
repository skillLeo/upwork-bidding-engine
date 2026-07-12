<?php

namespace Database\Seeders;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Database\Seeder;

/**
 * Populates leads across every status so the dashboard is fully clickable
 * on first run — an empty board tells you nothing about whether the UI works.
 */
class LeadSeeder extends Seeder
{
    public function run(): void
    {
        Lead::factory()->count(5)->create();

        Lead::factory()->count(2)->scoring()->create();

        foreach ([7, 7, 8, 8, 9, 9, 10, 10] as $score) {
            Lead::factory()->ready($score)->create();
        }

        for ($i = 0; $i < 6; $i++) {
            $lead = Lead::factory()->sent()->create();
            $this->attachClient($lead, ClientStage::Talking, withOutbound: true);
        }

        for ($i = 0; $i < 4; $i++) {
            $lead = Lead::factory()->replied()->create();
            $client = $this->attachClient($lead, ClientStage::Negotiating, withOutbound: true);

            Message::factory()->for($client)->inbound()->create([
                'text' => 'Thanks for the proposal — can you start next week? Also, what does your rate look like for a longer engagement past this first task?',
                'needs_hassam' => $i === 0,
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            $lead = Lead::factory()->won()->create();
            $client = $this->attachClient($lead, ClientStage::Won, withOutbound: true);

            Message::factory()->for($client)->inbound()->create([
                'text' => "Great, let's get started! Sending the contract over now.",
            ]);
            Message::factory()->for($client)->outbound()->create([
                'text' => 'Perfect, looking forward to it — I will get set up today and share a kickoff checklist.',
            ]);
        }

        Lead::factory()->count(6)->archived()->create();

        Lead::factory()->count(2)->archived(
            'Hard-filtered before scoring: proposal count exceeded the max_proposals rule.'
        )->create();

        Lead::factory()->count(2)->archived(
            'Hard-filtered before scoring: budget was below the configured floor.'
        )->create();
    }

    protected function attachClient(Lead $lead, ClientStage $stage, bool $withOutbound = false): Client
    {
        $client = Client::factory()->stage($stage)->create([
            'name' => $lead->title,
            'lead_id' => $lead->id,
            'budget_discussed' => $lead->budget,
        ]);

        $lead->update(['client_id' => $client->id]);

        if ($withOutbound) {
            Message::factory()->for($client)->outbound()->create([
                'text' => $lead->proposal_text ?? 'Sent proposal on Upwork.',
                'sent_at' => now()->subDays(random_int(1, 5)),
            ]);
        }

        return $client;
    }
}
