<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ProposalLinter;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechClaimLinterTest extends TestCase
{
    use RefreshDatabase;

    protected const PROJECT_FACTS = <<<'FACTS'
        PatrolTick: guard-management SaaS. Laravel + Vue web, Flutter mobile, PostgreSQL. Built the REST API the mobile app runs on.
        WorkDo: HRM SaaS platform. Laravel + Inertia.js + React + TypeScript + MySQL + Spatie.
        Stack: PHP, Laravel, Python, Django, FastAPI, Odoo, React, Next.js, Vue, Node, Express, MERN, TypeScript, Flutter, React Native, Dart, MySQL, PostgreSQL, Firebase.
        Anything not on this sheet is NOT in the track record and must never be claimed. Magento is not on this sheet.
        FACTS;

    protected function linter(): ProposalLinter
    {
        app(SettingsService::class)->set('project_facts', self::PROJECT_FACTS);

        return app(ProposalLinter::class);
    }

    public function test_the_real_incident_is_caught_mongodb_attributed_to_patroltick(): void
    {
        $text = "I read your brief closely and this is squarely in my lane.\n\n"
            ."I built PatrolTick, a guard-management SaaS. It uses MongoDB schemas for flexible data, Node.js and Express on the backend, and a dynamic React front end.\n\n"
            ."Plan: audit the data model, wire the API, ship a working demo. Done = a working demo you can click through.\n\n"
            ."Free this week for a quick call?\n\nHassam";

        $violations = $this->linter()->check($text);

        $this->assertNotEmpty($violations);

        $joined = implode(' | ', $violations);
        $this->assertStringContainsString('MongoDB', $joined, 'MongoDB never appears anywhere in project_facts - must be caught as an outright fabrication.');
        $this->assertStringContainsString('PatrolTick', $joined, 'Node/Express are real general skills but must be flagged when attributed to PatrolTick specifically, whose own stack is Laravel/Vue/Flutter/Postgres.');
    }

    public function test_a_technology_real_somewhere_but_wrongly_attributed_to_a_project_is_caught(): void
    {
        // Node/Express are genuine per the aggregate Stack line, so the
        // global fabrication check alone would miss this - only the
        // per-project attribution check catches it.
        $text = "PatrolTick is the closest match here. I built it with Node.js and Express for the API layer.\n\n"
            ."Plan: scope the schema, build the endpoints, test end to end. Done = a working API you can call.\n\n"
            ."Sound reasonable?\n\nHassam";

        $violations = $this->linter()->check($text);
        $joined = implode(' | ', $violations);

        $this->assertStringContainsString('Project-stack mismatch', $joined);
        $this->assertStringContainsString('Node.js', $joined);
    }

    public function test_the_same_technology_correctly_attributed_is_never_flagged(): void
    {
        $text = "WorkDo is the closest real match. I built it with Laravel, Inertia.js, React, and TypeScript, backed by MySQL.\n\n"
            ."Plan: define the schema, wire the endpoints, ship a demo. Done = a working demo you can click through.\n\n"
            ."Sound good?\n\nHassam";

        $violations = $this->linter()->check($text);
        $joined = implode(' | ', $violations);

        $this->assertStringNotContainsString('Fabricated tech claim', $joined);
        $this->assertStringNotContainsString('Project-stack mismatch', $joined);
    }

    public function test_a_general_skill_not_tied_to_any_named_project_is_never_flagged(): void
    {
        // "Node" is real per the aggregate Stack line but isn't attributed
        // to any specific named project here - a general skills claim,
        // not a fabrication.
        $text = "I've spent years across the MERN and Laravel worlds, including hands-on Node and Express work.\n\n"
            ."Plan: confirm scope, build the core flow, iterate from there. Done = a working core flow you can test.\n\n"
            ."What's the priority?\n\nHassam";

        $violations = $this->linter()->check($text);
        $joined = implode(' | ', $violations);

        $this->assertStringNotContainsString('Fabricated tech claim', $joined);
        $this->assertStringNotContainsString('Project-stack mismatch', $joined);
    }

    public function test_naming_a_technology_in_the_deny_list_line_does_not_make_it_look_allowed(): void
    {
        // Caught live: adding "Technologies NOT in stack: MongoDB, ..."
        // made a naive presence scan think MongoDB was IN the sheet,
        // because the word appears there - just in a forbidding sentence.
        app(SettingsService::class)->set(
            'project_facts',
            self::PROJECT_FACTS."\nTechnologies NOT in Hassam's stack, never claim: MongoDB, Ruby, Angular.\nAnything not on this sheet is NOT in the track record and must never be claimed. Magento is not on this sheet.",
        );

        $text = "PatrolTick is the closest match. Built with MongoDB and Magento integrations.\n\n"
            ."Plan: scope it, build it, ship it. Done = a working demo you can click through.\n\nSound good?\n\nHassam";

        $violations = app(ProposalLinter::class)->check($text);
        $joined = implode(' | ', $violations);

        $this->assertStringContainsString('MongoDB', $joined);
        $this->assertStringContainsString('Magento', $joined);
    }

    public function test_a_tech_term_is_attributed_to_the_nearest_preceding_project_not_any_project_in_the_paragraph(): void
    {
        // Real false positive caught live: one paragraph names TWO
        // projects. "Vue" describes the first (an unnamed "guard-management
        // SaaS" - PatrolTick's own description), then "SkillLeo Financial"
        // is named later in the same paragraph describing something else.
        // The old paragraph-wide check attributed Vue to SkillLeo Financial
        // just because they co-occurred, even though Vue was never
        // describing that project.
        $text = "I've successfully led development of a guard-management SaaS with Laravel and Vue, implementing role-based views. Moreover, in projects like SkillLeo Financial, I've focused on designing schemas for multi-tenant systems.\n\n"
            ."Plan: scope it, build it, ship it. Done = a working demo you can click through.\n\nSound good?\n\nHassam";

        $violations = $this->linter()->check($text);
        $joined = implode(' | ', $violations);

        $this->assertStringNotContainsString('Project-stack mismatch', $joined, $joined);
    }

    public function test_the_same_paragraph_still_catches_tech_attributed_to_the_project_named_right_before_it(): void
    {
        // The nearest-preceding-project fix must not lose detection just
        // because a second project is also named later in the paragraph.
        // Vue is real - via PatrolTick - so this isolates the per-project
        // logic specifically (the global check alone would pass Vue).
        // "Vue" sits between WorkDo (named first, doesn't have Vue) and
        // PatrolTick (named second, does have Vue) - nearest-preceding
        // must attribute it to WorkDo, the one actually being described.
        $text = "WorkDo is the closest match. I used Vue for the dashboards there, and I've also worked on PatrolTick for guard tracking context.\n\n"
            ."Plan: scope it, build it, ship it. Done = a working demo you can click through.\n\nSound good?\n\nHassam";

        $violations = $this->linter()->check($text);
        $joined = implode(' | ', $violations);

        $this->assertStringContainsString('Project-stack mismatch', $joined);
        $this->assertStringContainsString('"Vue" is attributed to WorkDo', $joined, $joined);
    }

    public function test_no_project_facts_configured_never_blocks_the_pipeline(): void
    {
        app(SettingsService::class)->set('project_facts', '');
        $violations = app(ProposalLinter::class)->check("Some proposal text.\n\nHassam");

        $this->assertSame([], array_filter($violations, fn ($v) => str_contains($v, 'Fabricated') || str_contains($v, 'mismatch')));
    }
}
