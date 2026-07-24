<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fallback tenant
    |--------------------------------------------------------------------------
    |
    | The slug bound when a request's host does not resolve to a tenant, and
    | the tenant every console command binds when no --tenant is given.
    |
    | This exists because the live host is upwork.skillleo.com, whose first
    | label is "upwork" — not a tenant slug. Resolving strictly by subdomain
    | with nothing else would 404 the entire production app the moment this
    | ships. It also keeps the scheduler alive: schedule:run invokes commands
    | with no options, and a global scope that throws on an unresolved tenant
    | would otherwise kill the poller, the queue drain and every health check.
    |
    | Set to null once real customers exist and every tenant reaches the app
    | on its own subdomain.
    |
    */
    'default_tenant_slug' => env('TENANCY_DEFAULT_TENANT', 'skillleo'),

    /*
    |--------------------------------------------------------------------------
    | Strict subdomain resolution
    |--------------------------------------------------------------------------
    |
    | When true, a host that does not match a tenant slug 404s instead of
    | falling back to default_tenant_slug. Flipping this to true is step one
    | of going genuinely multi-tenant; until then it stays false so the
    | current single-tenant host keeps working.
    |
    */
    'strict_subdomain' => env('TENANCY_STRICT_SUBDOMAIN', false),

    /*
    |--------------------------------------------------------------------------
    | Central domains
    |--------------------------------------------------------------------------
    |
    | Hosts that are never a tenant subdomain (local dev, bare apex). A
    | request on one of these skips subdomain parsing and goes straight to
    | the fallback.
    |
    */
    'central_domains' => [
        'localhost',
        '127.0.0.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform-only settings keys
    |--------------------------------------------------------------------------
    |
    | The scoring rubric and the drafting skill are the product itself, not a
    | customer's configuration — they stay platform defaults that get versioned
    | and improved for everyone. Mail credentials are infrastructure, not a
    | tenant concern. Writing a tenant override for any of these throws.
    |
    */
    'platform_only_keys' => [
        'signup_mode',
        'scoring_system_prompt',
        'proposal_skill',
        'stage_2_scoring_addendum',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
        // P5 platform console — plan display metadata is the product's own
        // catalog, not a customer's configuration.
        'platform_plan_definitions',
        'platform_default_scoring_model',
        'platform_default_proposal_model',
        'platform_default_review_model',
        // P6 — one Google OAuth app registration for the whole deployment.
        'google_oauth_client_id',
        'google_oauth_client_secret',
    ],

];
