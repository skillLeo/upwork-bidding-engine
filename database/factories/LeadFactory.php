<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @var array<int, string>
     */
    protected array $titles = [
        'Laravel Developer Needed to Rebuild Legacy Booking System',
        'Senior Next.js Engineer for SaaS Dashboard Redesign',
        'Full Stack Laravel + React Developer for Internal Tooling',
        'Flutter Developer for Cross-Platform Delivery App',
        'Fix Bugs in Existing Laravel + Vue Marketplace',
        'Build REST API in Laravel for Mobile App Backend',
        'Node.js Developer for Real-Time Chat Feature',
        'MERN Stack Developer for E-commerce MVP',
        'Python Automation Script for Data Scraping',
        'Laravel Livewire Developer for Admin Panel',
        'React Native Developer to Finish Half-Built App',
        'Long-Term Laravel Maintenance & Feature Development',
        'Next.js Frontend for Existing Laravel API',
        'WordPress to Laravel Migration Specialist',
        'PHP Developer for Payment Gateway Integration',
    ];

    /**
     * @var array<int, string>
     */
    protected array $skillPool = [
        'Laravel', 'PHP', 'Vue.js', 'React', 'Next.js', 'Node.js', 'TypeScript',
        'MySQL', 'PostgreSQL', 'REST API', 'Livewire', 'Tailwind CSS', 'Flutter',
        'MongoDB', 'AWS', 'Docker', 'GraphQL', 'Stripe API',
    ];

    /**
     * @var array<int, string>
     */
    protected array $briefFragments = [
        'We are looking for an experienced developer to join our small team on a long-term basis.',
        'The codebase is already in production, so care and testing matter more than speed.',
        'You will work directly with the founder, no layers of management.',
        'Please only apply if you have shipped something similar before — link examples in your proposal.',
        'This starts as a paid trial task before we commit to a longer contract.',
        'We need someone who communicates clearly and proactively, not just someone who can code.',
        'Budget is flexible for the right person who can start this week.',
    ];

    public function definition(): array
    {
        $postedAt = fake()->dateTimeBetween('-14 days', 'now');
        $budgetType = fake()->randomElement(['fixed', 'hourly']);
        $hireRate = fake()->numberBetween(0, 100);

        return [
            'external_id' => 'upwork_'.fake()->unique()->numerify('####################'),
            'title' => fake()->randomElement($this->titles),
            'full_brief' => fake()->paragraph(3, true).' '.implode(' ', fake()->randomElements($this->briefFragments, 3)),
            'skills' => fake()->randomElements($this->skillPool, fake()->numberBetween(3, 7)),
            'url' => 'https://www.upwork.com/jobs/~'.fake()->unique()->numerify('01##################'),
            'budget' => $budgetType === 'fixed'
                ? '$'.fake()->numberBetween(1, 60).'00 fixed'
                : '$'.fake()->numberBetween(8, 85).'/hr',
            'budget_type' => $budgetType,
            'client_country' => fake()->randomElement(['United States', 'United Kingdom', 'Canada', 'Australia', 'Germany', 'United Arab Emirates', 'Pakistan', 'India', 'Netherlands']),
            'client_spend' => fake()->randomElement(['$0', '$350', '$1.2K', '$5.4K', '$18K', '$62K', '$140K+']),
            'client_hire_rate' => $hireRate.'%',
            'client_hire_rate_pct' => (float) $hireRate,
            'payment_verified' => fake()->boolean(80),
            'proposal_count' => fake()->numberBetween(0, 45),
            'connects_required' => fake()->randomElement([2, 4, 6, 8, 10, 16]),
            'score' => null,
            'score_reason' => null,
            'proposal_text' => null,
            'status' => LeadStatus::New,
            'is_favorite' => false,
            'client_id' => null,
            'posted_at' => $postedAt,
            'created_at' => $postedAt,
        ];
    }

    protected function sampleProposal(string $title): string
    {
        return "Hi, I read through the brief for \"{$title}\" and this is squarely in my lane — I've shipped very similar work recently.\n\n"
            ."Here's how I'd approach it: start with a short discovery call to lock down scope, then work in small reviewable chunks so you're never waiting on a big-bang delivery. I'd flag risks early rather than surprise you at the end.\n\n"
            ."A bit about me: I've spent the last several years building and maintaining production systems in this exact stack, including performance and API integration work. Happy to share relevant samples.\n\n"
            .'Free for a quick call this week to talk specifics?';
    }

    public function scoring(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LeadStatus::Scoring,
        ]);
    }

    public function ready(?int $score = null): static
    {
        return $this->state(function (array $attributes) use ($score) {
            $finalScore = $score ?? fake()->numberBetween(7, 10);

            return [
                'status' => LeadStatus::Ready,
                'score' => $finalScore,
                'score_reason' => "Strong match on stack and budget. Client has {$attributes['client_hire_rate']} hire rate and verified payment, proposal count ({$attributes['proposal_count']}) is still low enough to stand out.",
                'proposal_text' => $this->sampleProposal($attributes['title']),
            ];
        });
    }

    public function sent(?int $score = null): static
    {
        return $this->ready($score)->state(fn (array $attributes) => [
            'status' => LeadStatus::Sent,
        ]);
    }

    public function replied(?int $score = null): static
    {
        return $this->sent($score)->state(fn (array $attributes) => [
            'status' => LeadStatus::Replied,
        ]);
    }

    public function won(?int $score = null): static
    {
        return $this->replied($score)->state(fn (array $attributes) => [
            'status' => LeadStatus::Won,
        ]);
    }

    /** Bid, and it is over without a contract — still a sent proposal. */
    public function lost(?int $score = null): static
    {
        return $this->sent($score)->state(fn (array $attributes) => [
            'status' => LeadStatus::Lost,
        ]);
    }

    public function archived(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LeadStatus::Archived,
            'score' => fake()->boolean(50) ? fake()->numberBetween(1, 6) : null,
            'score_reason' => $reason ?? fake()->randomElement([
                'Below the configured score cutoff — stack match was weak and budget was on the low end.',
                'Hard-filtered before scoring: proposal count exceeded the max_proposals rule.',
                'Hard-filtered before scoring: budget was below the configured floor.',
                'Hard-filtered before scoring: brief contained a red-flag phrase ("unpaid sample").',
                'Zero client hire history combined with a tiny budget — filtered by zero_history_budget_floor.',
            ]),
        ]);
    }

    public function withExternalId(string $externalId): static
    {
        return $this->state(fn () => ['external_id' => $externalId]);
    }
}
