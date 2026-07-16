<?php

namespace Database\Seeders;

use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Unlike SettingsSeeder (rule defaults only, secrets deliberately left
 * blank for an admin to fill in via the UI), this one *does* seed real
 * SMTP credentials — because the user explicitly asked for a seeder here
 * instead of typing them into Settings by hand.
 *
 * The actual secret values live only in .env (MAIL_SEED_*, never
 * committed), not in this file — run with `php artisan db:seed
 * --class=MailSettingsSeeder`. A no-op if those env vars aren't set, so a
 * fresh clone / `db:seed` without them doesn't silently write blank SMTP
 * settings over ones an admin already configured through the UI.
 */
class MailSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (! env('MAIL_SEED_USERNAME')) {
            $this->command?->warn('MAIL_SEED_USERNAME not set in .env — skipping mail settings seed.');

            return;
        }

        app(SettingsService::class)->setMany([
            'mail_host' => env('MAIL_SEED_HOST', 'smtp.hostinger.com'),
            'mail_port' => (int) env('MAIL_SEED_PORT', 465),
            'mail_username' => env('MAIL_SEED_USERNAME'),
            'mail_password' => env('MAIL_SEED_PASSWORD'),
            'mail_encryption' => env('MAIL_SEED_ENCRYPTION', 'ssl'),
            'mail_from_address' => env('MAIL_SEED_FROM_ADDRESS', env('MAIL_SEED_USERNAME')),
            'mail_from_name' => env('MAIL_SEED_FROM_NAME', 'SkillLeo'),
        ]);

        $this->command?->info('Mail (SMTP) settings seeded from .env.');
    }
}
