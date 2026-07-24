<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForTenants;
use App\Services\SettingsService;
use App\Services\WebPushService;
use Illuminate\Console\Command;

/**
 * Generates the Web Push VAPID keypair once and stores it in Settings. Run
 * once per environment; refuses to overwrite unless --force so existing device
 * subscriptions (which are tied to the current public key) aren't invalidated.
 */
class GenerateVapidKeysCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'push:generate-vapid-keys {--force : Regenerate even if keys already exist}';

    protected $description = 'Generate the Web Push (VAPID) keypair used to send browser push notifications';

    public function handle(WebPushService $push, SettingsService $settings): int
    {
        return $this->asPlatform(fn () => $this->runForTenant($push, $settings));
    }

    protected function runForTenant(WebPushService $push, SettingsService $settings): int
    {
        if ($push->configured() && ! $this->option('force')) {
            $this->info('VAPID keys already exist. Use --force to regenerate (this invalidates every current device subscription).');

            return self::SUCCESS;
        }

        $keys = $push->generateKeys();

        $this->info('Generated and stored a new VAPID keypair.');
        $this->line('Public key: '.$keys['publicKey']);

        return self::SUCCESS;
    }
}
