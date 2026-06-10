<?php

/*
|--------------------------------------------------------------------------
| HelpStripe application settings
|--------------------------------------------------------------------------
|
| App-specific configuration lives in its own file rather than being
| bolted onto a framework config. Everything here follows the standard
| Laravel config pattern: env() is only ever called HERE — the rest of
| the app reads config('helpstripe.…'), which keeps `php artisan
| config:cache` safe (cached configs never re-read the environment).
|
*/

return [

    /*
    | Static bearer token for the JSON intake API (POST /api/v1/requests).
    | Phase 3 teaches the middleware + config mechanics with a single
    | shared token; Laravel Sanctum is the production-grade replacement
    | (per-client tokens, abilities, revocation) — see the tour doc's
    | Future Considerations.
    */
    'api_token' => env('HELPSTRIPE_API_TOKEN'),

    /*
    | The domain inbound mail arrives on (and outbound threading headers
    | are minted against). Mailbox addresses in the demo seeder derive
    | from this so the seeded data matches whatever domain is wired up
    | in Resend.
    */
    'inbound_domain' => env('HELPSTRIPE_INBOUND_DOMAIN', 'helpstripe.test'),

    /*
    | Inbound attachments larger than this are skipped (a private note on
    | the request records the skip). Resend's attachment listing reports
    | size before download, so oversize files are never fetched at all.
    */
    'max_attachment_bytes' => (int) env('HELPSTRIPE_MAX_ATTACHMENT_BYTES', 10 * 1024 * 1024),

];
