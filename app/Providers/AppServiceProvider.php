<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $identifier = $user instanceof \App\Models\User ? (string) $user->id : $request->ip();

            return Limit::perMinute(120)->by($identifier);
        });

        // Public webhooks (Vollna, WhatsApp) get their own, stricter, IP-based limit.
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        $this->configureDynamicMail();

        // The default notification points at a named `password.reset` web
        // route, which doesn't exist here (API-only backend, SPA does the
        // routing) - point it at the frontend's own reset page instead.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim((string) config('skillleo.frontend_url'), '/');

            return "{$frontend}/reset-password?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    /**
     * Pulls SMTP creds from Settings (not .env) so they're changeable from
     * the UI without a redeploy. Guarded on the table existing so a fresh
     * `migrate` (before the settings table exists) never breaks boot, and
     * on a host actually being set so an unconfigured install just keeps
     * .env's inert `log` mailer instead of half-applying empty SMTP config.
     */
    protected function configureDynamicMail(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $mail = app(SettingsService::class)->mailConfig();

        if ($mail['host'] === '') {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $mail['host'],
            'port' => $mail['port'],
            'encryption' => $mail['encryption'] ?: null,
            'username' => $mail['username'],
            'password' => $mail['password'],
        ]);
        Config::set('mail.from', [
            'address' => $mail['from_address'] ?: $mail['username'],
            'name' => $mail['from_name'],
        ]);
    }
}
