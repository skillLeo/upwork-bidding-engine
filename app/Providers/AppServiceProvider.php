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
        // Bound to the interface so tests (and any host without the imap
        // extension) can swap in a fake without touching the poller.
        $this->app->bind(
            \App\Services\Mail\Mailbox::class,
            \App\Services\Mail\ImapMailbox::class,
        );
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

        // Password guessing: keyed on IP + the submitted email so one
        // attacker can't burn through many accounts by spreading requests
        // across emails, and one email can't be hammered from many IPs
        // without also hitting the IP-only webhooks-style limit elsewhere.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip().'|'.$request->input('email'));
        });

        // A 6-digit OTP is only ~1M possibilities - this has to be tight
        // (not the generic 60/min webhooks limit) since the code's own
        // 10-minute expiry is the only other thing standing between a
        // fast guesser and a valid session.
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->input('challenge'));
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
