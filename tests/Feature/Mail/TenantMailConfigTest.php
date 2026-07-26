<?php

namespace Tests\Feature\Mail;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Regression cover for the bug that meant NO email ever left production.
 *
 * mail_* settings are tenant-scoped, but AppServiceProvider::boot() ran
 * before any tenant was bound, so reading them threw MissingTenantException
 * — which a bare `catch (\Throwable) { return; }` swallowed on every single
 * request. mail.default stayed on .env's 'log' and invitations, password
 * resets, login OTPs and lead notifications were all written to a log file
 * instead of being sent, silently, for weeks.
 *
 * The fix resolves mail config at mailer-resolution time (see
 * TenantAwareMailManager), by which point a tenant is always bound.
 */
class TenantMailConfigTest extends TestCase
{
    use RefreshDatabase;

    private function storeSmtpSettings(): void
    {
        app(SettingsService::class)->setMany([
            'mail_host' => 'smtp.example.test',
            'mail_port' => 465,
            'mail_username' => 'contact@example.test',
            'mail_password' => 'super-secret',
            'mail_encryption' => 'ssl',
            'mail_from_address' => 'contact@example.test',
            'mail_from_name' => 'Example Workspace',
        ]);
    }

    public function test_the_workspaces_smtp_settings_are_applied_when_the_mailer_is_resolved(): void
    {
        $this->storeSmtpSettings();

        // Nothing has configured mail yet — this is the state boot leaves.
        Config::set('mail.default', 'array');

        app('mail.manager')->mailer();

        $this->assertSame('smtp', config('mail.default'), 'the mailer must switch to SMTP, not stay on the inert default');
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('ssl', config('mail.mailers.smtp.encryption'));
        $this->assertSame('contact@example.test', config('mail.mailers.smtp.username'));
        $this->assertSame('contact@example.test', config('mail.from.address'));
        $this->assertSame('Example Workspace', config('mail.from.name'));
    }

    public function test_the_tenant_aware_manager_is_the_bound_mail_manager(): void
    {
        $this->assertInstanceOf(\App\Mail\TenantAwareMailManager::class, app('mail.manager'));
    }

    public function test_resolving_mail_with_no_tenant_bound_does_not_throw(): void
    {
        $this->storeSmtpSettings();
        app(\App\Tenancy\TenantContext::class)->forget();

        // Must degrade quietly rather than fatal — but it also must NOT
        // silently claim success, so the config stays untouched.
        Config::set('mail.default', 'array');

        app('mail.manager')->mailer();

        $this->assertSame('array', config('mail.default'));
    }

    public function test_an_unconfigured_workspace_leaves_the_default_mailer_alone(): void
    {
        // No mail_host set at all.
        Config::set('mail.default', 'array');

        app('mail.manager')->mailer();

        $this->assertSame('array', config('mail.default'));
    }

    public function test_sending_actually_dials_the_workspace_smtp_host(): void
    {
        $this->storeSmtpSettings();

        // The heart of the regression: before the fix this call succeeded
        // silently, because the message went to the 'log' mailer and was
        // written to storage/logs instead of being sent. Now it must reach a
        // real SMTP transport — proven by it trying, and failing, to open a
        // connection to the configured (non-existent) host. A test that
        // "passes" quietly here is the exact bug this file exists to catch.
        try {
            \Illuminate\Support\Facades\Mail::to('invitee@example.test')
                ->send(new \App\Mail\LeadNotificationMail('Test subject', 'body', '/leads', 'https://app.test'));

            $this->fail('Mail was accepted without contacting SMTP — it is being swallowed by an inert mailer again.');
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            $this->assertStringContainsString('smtp.example.test', $e->getMessage());
        }

        $this->assertSame('smtp', config('mail.default'));
    }
}
