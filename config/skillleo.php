<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Infra-level config only (like DB/Redis) — used to build deep links back
    | into the dashboard for WhatsApp notifications. Not a secret.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:3000'),

    /*
    |--------------------------------------------------------------------------
    | Dev quick login
    |--------------------------------------------------------------------------
    |
    | Renders one-click "Admin"/"Bidder" buttons on the sign-in page and enables
    | POST /api/auth/dev-login, which issues a token for the first user of that
    | role WITHOUT a password.
    |
    | This is passwordless access to the dashboard. While it is on, anyone who
    | can reach the site can sign in as admin — so it must be off before the
    | app is treated as live. It is opt-in per environment and defaults to off.
    |
    | Credentials are deliberately never shipped to the frontend: the server
    | resolves the user, so no password exists in the JS bundle to be read.
    |
    */

    'dev_quick_login' => (bool) env('DEV_QUICK_LOGIN', false),

];
