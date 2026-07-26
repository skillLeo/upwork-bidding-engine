<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request/command, so the global scope, the
        // middleware, jobs and commands all read and write the same context.
        // A non-singleton here would mean the scope silently seeing no
        // tenant while the middleware believed it had bound one.
        $this->app->singleton(\App\Tenancy\TenantContext::class);

        // Bound to the interface so tests (and any host without the imap
        // extension) can swap in a fake without touching the poller.
        $this->app->bind(
            \App\Services\Mail\Mailbox::class,
            \App\Services\Mail\ImapMailbox::class,
        );

        // Workspace SMTP settings are tenant-scoped, so they cannot be read at
        // boot (no tenant is bound until middleware runs). This manager applies
        // them when mail is actually resolved instead — see
        // TenantAwareMailManager for the bug this replaced. extend(), not
        // singleton(), because Laravel's own MailServiceProvider is deferred
        // and would otherwise re-bind over the top of us.
        $this->app->extend('mail.manager', function ($manager, $app) {
            return new \App\Mail\TenantAwareMailManager($app);
        });
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

        // A COARSE IP backstop only. The real per-account rule (5 attempts,
        // then exponential backoff to a 15-minute lock) lives in
        // App\Services\Auth\LoginThrottle, which is the authority. This one
        // exists purely to blunt a single IP spraying many addresses — set
        // wide enough that it never fires before LoginThrottle does, because
        // a limiter that trips first would mask the real lockout and its
        // clearer message.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Password reset request: 3 per email per hour. Keyed on the email
        // rather than the IP because the abuse this stops is mail-bombing one
        // person's inbox, which an attacker would happily do from many IPs.
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perHour(3)->by(\Illuminate\Support\Str::lower((string) $request->input('email')));
        });

        // A 6-digit OTP is only ~1M possibilities - this has to be tight
        // (not the generic 60/min webhooks limit) since the code's own
        // 10-minute expiry is the only other thing standing between a
        // fast guesser and a valid session.
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->input('challenge'));
        });

        // Per-user permission DENIES are enforced in User::hasPermissionTo()
        // (see the comment there for why NOT a Gate::before: Spatie's own
        // before hook registers first and would win). Nothing to register
        // here — noted so nobody re-adds a second, dead hook.

        // Idle-token expiry, enforced as part of token VALIDATION rather
        // than as middleware. Sanctum stamps last_used_at = now() while
        // authenticating, before any middleware runs, so a middleware could
        // never observe a stale timestamp. This callback runs inside
        // isValidAccessToken(), i.e. before that stamp. See TokenFreshness.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(
            fn ($accessToken, bool $isValid) => app(\App\Services\Auth\TokenFreshness::class)
                ->enforce($accessToken, $isValid)
        );

        // NOTE: mail is deliberately NOT configured here. Its settings are
        // tenant-scoped and no tenant is bound at boot — see
        // TenantAwareMailManager, registered in register() above.
        $this->configureDynamicGoogleOAuth();

        // The default notification points at a named `password.reset` web
        // route, which doesn't exist here (API-only backend, SPA does the
        // routing) - point it at the frontend's own reset page instead.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim((string) config('skillleo.frontend_url'), '/');

            return "{$frontend}/reset-password?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    /**
     * Configured from Settings > Platform console, not .env, so the client
     * id/secret can be added without a redeploy. Unlike mail (see
     * TenantAwareMailManager), these are platform-level keys that resolve
     * fine at boot with no tenant bound.
     * Settings > Platform console, not .env, so the client id/secret can be
     * added without a redeploy.
     */
    protected function configureDynamicGoogleOAuth(): void
    {
        try {
            $settings = app(SettingsService::class);
            $clientId = (string) $settings->get('google_oauth_client_id');
            $clientSecret = (string) $settings->get('google_oauth_client_secret');
        } catch (\Throwable) {
            return;
        }

        if ($clientId === '' || $clientSecret === '') {
            return;
        }

        // The redirect target is a BACKEND route (this app resolves the
        // account before handing the browser back to the SPA) — app.url,
        // not frontend_url, even though the two happen to share a host on
        // this single-domain deployment.
        $backend = rtrim((string) config('app.url'), '/');

        Config::set('services.google', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $backend.'/api/auth/google/callback',
        ]);
    }
}
