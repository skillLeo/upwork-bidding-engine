<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        Template::create([
            'name' => 'Laravel fixed-price opener',
            'style' => 'concise',
            'body' => "Hi, I read through the brief and this is squarely in my lane — I've built and shipped very similar Laravel work recently.\n\n"
                ."Proposed approach: a short discovery call to lock scope, then delivery in small reviewable chunks so you're never waiting on a big-bang release.\n\n"
                .'Happy to share relevant samples. Free for a quick call this week?',
        ]);

        Template::create([
            'name' => 'Next.js dashboard opener',
            'style' => 'detailed',
            'body' => "Hi — this looks like a great fit. I specialize in exactly this: Next.js App Router dashboards on top of an existing API, with typed data fetching, sensible loading/error states, and a component library that doesn't feel templated.\n\n"
                ."A few questions before I finalize scope: is there an existing design system or should I propose one, and is the backend API already stable or still in flux?\n\n"
                .'I can start this week and would suggest a short paid discovery task first if you want to de-risk before a longer commitment.',
        ]);

        Template::create([
            'name' => 'Long-term maintenance pitch',
            'style' => 'technical',
            'body' => "Hi, I read the brief — long-term maintenance work like this is most of what I do, and I'd rather build a reliable working relationship than chase one-off gigs.\n\n"
                ."I'd start with a short audit of the current codebase (queues, error tracking, test coverage) so we both know what we're working with, then move to a regular cadence for fixes and features.\n\n"
                .'Available to start immediately, and comfortable with async communication plus a weekly check-in.',
        ]);
    }
}
