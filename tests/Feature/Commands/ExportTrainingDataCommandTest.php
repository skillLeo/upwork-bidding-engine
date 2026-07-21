<?php

namespace Tests\Feature\Commands;

use App\Models\Lead;
use App\Models\ProposalVersion;
use App\Services\ProposalVersionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExportTrainingDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/training'));
        parent::tearDown();
    }

    /** Load and JSON-decode every line of an exported file. */
    protected function readJsonl(string $path): array
    {
        $this->assertFileExists($path);
        $lines = array_filter(explode("\n", trim(File::get($path))), fn ($l) => $l !== '');

        return array_map(function ($line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "Line is not valid JSON: {$line}");

            return $decoded;
        }, $lines);
    }

    public function test_exports_sent_proposals_as_valid_chat_jsonl(): void
    {
        $lead = Lead::factory()->sent()->create();
        app(ProposalVersionRecorder::class)->record($lead, 'The final sent proposal body.', 'initial_draft');
        Lead::query()->find($lead->id)->proposalVersions()->update([
            'is_sent' => true,
            'sent_at' => now(),
        ]);

        $month = now()->format('Y-m');
        $this->artisan('proposals:export-training-data')->assertSuccessful();

        $rows = $this->readJsonl(storage_path("app/training/train-{$month}.jsonl"));
        $this->assertCount(1, $rows);

        $messages = $rows[0]['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('The final sent proposal body.', $messages[2]['content']);
        $this->assertStringContainsString($lead->title, $messages[1]['content']);
    }

    public function test_falls_back_to_live_proposal_text_when_no_sent_version_exists(): void
    {
        // A lead sent before versioning existed: proposal_text but no versions.
        $lead = Lead::factory()->sent()->create();

        $month = now()->format('Y-m');
        $this->artisan('proposals:export-training-data')->assertSuccessful();

        $rows = $this->readJsonl(storage_path("app/training/train-{$month}.jsonl"));
        $this->assertCount(1, $rows);
        $this->assertSame($lead->proposal_text, $rows[0]['messages'][2]['content']);
    }

    public function test_only_flag_limits_to_replied_and_won(): void
    {
        Lead::factory()->sent()->create();     // excluded by --replied-only
        Lead::factory()->replied()->create();  // included
        Lead::factory()->won()->create();      // included
        Lead::factory()->ready()->create();    // never eligible (not sent)

        $month = now()->format('Y-m');
        $this->artisan('proposals:export-training-data', ['--replied-only' => true])->assertSuccessful();

        $rows = $this->readJsonl(storage_path("app/training/train-{$month}.jsonl"));
        $this->assertCount(2, $rows);
    }

    public function test_excludes_leads_outside_the_requested_month(): void
    {
        $lead = Lead::factory()->sent()->create();
        // Push its only signal (updated_at, no sent version) into last month.
        Lead::withoutTimestamps(fn () => $lead->forceFill(['updated_at' => now()->subMonthNoOverflow()])->save());

        $month = now()->format('Y-m');
        $this->artisan('proposals:export-training-data')->assertSuccessful();

        // Nothing eligible -> the command writes no file at all.
        $this->assertFileDoesNotExist(storage_path("app/training/train-{$month}.jsonl"));
    }

    public function test_split_holds_back_a_validation_set(): void
    {
        Lead::factory()->count(4)->sent()->create();

        $month = now()->format('Y-m');
        $this->artisan('proposals:export-training-data', ['--split' => 0.5])->assertSuccessful();

        $train = $this->readJsonl(storage_path("app/training/train-{$month}.jsonl"));
        $validation = $this->readJsonl(storage_path("app/training/validation-{$month}.jsonl"));

        $this->assertCount(2, $train);
        $this->assertCount(2, $validation);
    }

    public function test_export_never_mutates_leads_or_versions(): void
    {
        $lead = Lead::factory()->sent()->create();
        $version = app(ProposalVersionRecorder::class)->record($lead, 'Sent body.', 'initial_draft');
        $version->update(['is_sent' => true, 'sent_at' => now()]);

        $leadBefore = $lead->fresh()->toArray();
        $versionBefore = $version->fresh()->toArray();

        $this->artisan('proposals:export-training-data')->assertSuccessful();

        $this->assertEquals($leadBefore, $lead->fresh()->toArray());
        $this->assertEquals($versionBefore, ProposalVersion::find($version->id)->toArray());
    }
}
