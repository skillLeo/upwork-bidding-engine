<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ProposalLinter;
use App\Services\Ai\ScoringService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three stack lists (core/secondary/excluded) are the single source of
 * truth for what is in scope. These tests prove the scorer, the writer, and
 * the linter all read them - and that moving a stack between lists changes
 * behavior with no code change or deploy.
 */
class StackListsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    private function linter(): ProposalLinter
    {
        return app(ProposalLinter::class);
    }

    /** @param array<int, string> $violations */
    private function has(array $violations, string $needle): bool
    {
        return array_filter($violations, fn (string $v) => str_contains($v, $needle)) !== [];
    }

    public function test_stack_context_renders_the_three_lists(): void
    {
        $this->settings()->setMany([
            'core_stacks' => ['Laravel', 'Vue'],
            'secondary_stacks' => ['Node.js'],
            'excluded_stacks' => ['Golang', 'Ruby'],
        ]);

        $ctx = $this->settings()->stackContext();

        $this->assertStringContainsString('CORE STACKS', $ctx);
        $this->assertStringContainsString('Laravel, Vue', $ctx);
        $this->assertStringContainsString('SECONDARY STACKS', $ctx);
        $this->assertStringContainsString('Node.js', $ctx);
        $this->assertStringContainsString('EXCLUDED STACKS', $ctx);
        $this->assertStringContainsString('Golang, Ruby', $ctx);
    }

    public function test_scoring_prompt_injects_the_stack_lists(): void
    {
        $this->settings()->setMany([
            'scoring_system_prompt' => 'RUBRIC BODY',
            'core_stacks' => ['Laravel'],
            'excluded_stacks' => ['Golang'],
        ]);

        $prompt = app(ScoringService::class)->systemPrompt();

        $this->assertStringContainsString('RUBRIC BODY', $prompt);
        $this->assertStringContainsString('CORE STACKS', $prompt);
        $this->assertStringContainsString('Laravel', $prompt);
        $this->assertStringContainsString('Golang', $prompt);
    }

    public function test_an_excluded_stack_claimed_in_a_proposal_is_a_violation(): void
    {
        $this->settings()->setMany([
            'excluded_stacks' => ['Ruby on Rails'],
            'project_facts' => 'PatrolTick: guard SaaS. Laravel + Vue.',
        ]);

        $violations = $this->linter()->check("I can build this with Ruby on Rails and ship fast.\n\nHassam");

        $this->assertTrue($this->has($violations, 'Excluded tech claim'));
    }

    public function test_a_secondary_stack_mentioned_as_a_general_capability_is_allowed(): void
    {
        $this->settings()->setMany([
            'core_stacks' => ['Laravel'],
            'secondary_stacks' => ['Node.js'],
            'excluded_stacks' => ['Golang'],
            'project_facts' => 'PatrolTick: guard SaaS. Laravel + Vue.',
        ]);

        // General capability, not tied to a named project -> allowed.
        $violations = $this->linter()->check("I am comfortable building Node.js services when a project calls for it.\n\nHassam");

        $this->assertFalse($this->has($violations, 'tech claim'));
        $this->assertFalse($this->has($violations, 'mismatch'));
    }

    public function test_moving_a_stack_to_excluded_flips_the_linter_with_no_deploy(): void
    {
        $s = $this->settings();
        $s->setMany([
            'secondary_stacks' => ['GraphQL'],
            'excluded_stacks' => [],
            'project_facts' => 'PatrolTick: guard SaaS. Laravel + Vue.',
        ]);

        $letter = "I am comfortable with GraphQL APIs.\n\nHassam";
        $this->assertFalse($this->has($this->linter()->check($letter), 'Excluded'));

        // Same text, GraphQL now excluded -> becomes a hard violation.
        $s->setMany(['secondary_stacks' => [], 'excluded_stacks' => ['GraphQL']]);
        $this->assertTrue($this->has($this->linter()->check($letter), 'Excluded tech claim'));
    }

    public function test_a_two_char_excluded_term_without_a_tuned_pattern_is_skipped(): void
    {
        // A bare "Go" would match ordinary English; only "Golang" is enforced,
        // so the operator typing "Go" must not flood proposals with false hits.
        $this->settings()->setMany([
            'excluded_stacks' => ['Go'],
            'project_facts' => 'PatrolTick: guard SaaS. Laravel + Vue.',
        ]);

        $violations = $this->linter()->check("Let us go through the requirements together and get started.\n\nHassam");

        $this->assertFalse($this->has($violations, 'Excluded'));
    }

    public function test_rules_stack_keywords_is_core_plus_secondary_for_legacy_consumers(): void
    {
        $this->settings()->setMany([
            'core_stacks' => ['Laravel', 'Vue'],
            'secondary_stacks' => ['Node.js'],
            'excluded_stacks' => ['Golang'],
        ]);

        $this->assertSame(['Laravel', 'Vue', 'Node.js'], $this->settings()->rules()['stack_keywords']);
    }
}
