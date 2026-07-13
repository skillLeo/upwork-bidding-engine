<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | In production the Vue SPA is served from this same Laravel app, so
    | API calls are same-origin and this never triggers. It only matters
    | for local dev, where the Vite dev server runs on its own port and
    | needs `FRONTEND_URL` set to allow it through. Sanctum bearer tokens
    | (not cookie auth) are used either way, so credentials support is
    | left off by default.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', 'http://127.0.0.1:3000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
