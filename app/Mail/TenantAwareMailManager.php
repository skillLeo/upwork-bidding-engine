<?php

namespace App\Mail;

use App\Services\SettingsService;
use App\Tenancy\TenantContext;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Applies the workspace's own SMTP settings at the moment mail is actually
 * resolved, rather than at application boot.
 *
 * Why this exists: mail_* settings are tenant-scoped (tenant_id on the
 * settings row), but AppServiceProvider::boot() runs BEFORE any middleware,
 * so no tenant is bound yet and reading them throws MissingTenantException.
 * The previous implementation configured mail at boot inside a
 * `catch (\Throwable) { return; }`, so on every single request that exception
 * was swallowed silently and mail.default stayed on whatever .env said —
 * 'log' in production. The result: invitations, password resets, login OTPs
 * and lead-notification emails were all written to storage/logs and never
 * sent, with nothing anywhere reporting a failure.
 *
 * Resolving here instead means the tenant is always already bound: a web
 * request has passed ResolveTenant, and a queued job or console command has
 * entered its own runAs()/forTenant() block. It is also the single choke
 * point for every mail path, so there is no list of call sites to keep in
 * sync.
 *
 * Failures are LOGGED, never silent — a mail misconfiguration must be
 * discoverable without reading the database.
 */
class TenantAwareMailManager extends MailManager
{
    /**
     * The tenant id the current mail.* config belongs to. -1 means "not
     * configured from settings", which is distinct from null ("configured
     * for no tenant") and forces a re-read on the next attempt.
     */
    protected int|string $configuredForTenant = -1;

    /** @param  string|null  $name */
    public function mailer($name = null)
    {
        $this->syncTenantMailConfig();

        return parent::mailer($name);
    }

    /**
     * Point mail.* at this workspace's SMTP credentials. Cheap on the happy
     * path: SettingsService::all() is cache-backed, and this no-ops entirely
     * once the current tenant's config is already applied.
     */
    protected function syncTenantMailConfig(): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId !== null && $tenantId === $this->configuredForTenant) {
            return;
        }

        if ($tenantId === null) {
            // Nothing safe to read. Leave whatever config is in place and say
            // so out loud rather than silently sending from the wrong account.
            Log::warning('Mail was requested with no tenant bound; workspace SMTP settings could not be applied.');

            return;
        }

        try {
            $mail = app(SettingsService::class)->mailConfig();
        } catch (\Throwable $e) {
            // A fresh install before the settings table exists is the one
            // legitimate case. It is still logged — this catch used to hide
            // a real bug for weeks.
            Log::warning('Could not read workspace mail settings: '.$e->getMessage());

            return;
        }

        if (trim((string) $mail['host']) === '') {
            Log::warning("No SMTP host configured for tenant [{$tenantId}]; mail will use the default mailer and may not be delivered.");
            $this->configuredForTenant = $tenantId;

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

        // Drop any mailer built from the previous tenant's (or .env's) config
        // so the next resolve picks up what was just set. Without this a queue
        // worker serving two workspaces would send the second one's mail
        // through the first one's SMTP account.
        $this->forgetMailers();

        $this->configuredForTenant = $tenantId;
    }
}
