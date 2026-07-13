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

];
