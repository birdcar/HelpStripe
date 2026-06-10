<?php

use App\Jobs\ProcessInboundEmail;
use App\Support\Resend\ResendSignatureValidator;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;

/*
|--------------------------------------------------------------------------
| spatie/laravel-webhook-client
|--------------------------------------------------------------------------
|
| The package's architecture is "store, then process": the HTTP request
| is validated (signature), persisted as a WebhookCall row, and a queued
| job is dispatched — all before any business logic runs. The endpoint
| answers 200 in milliseconds, so the sender (Resend) never retries just
| because OUR processing was slow; genuine processing failures land in
| failed_jobs where they can be inspected and retried.
|
*/

return [
    'configs' => [
        [
            /*
             * Each named config is one receiving endpoint. Ours is wired to
             * Route::webhooks('webhooks/resend', 'resend') in routes/web.php.
             */
            'name' => 'resend',

            /*
             * Resend signs webhooks with a svix signing secret (the
             * `whsec_…` value shown when creating the webhook endpoint in
             * the Resend dashboard).
             */
            'signing_secret' => env('RESEND_WEBHOOK_SECRET'),

            /*
             * Informational here: Resend sends svix-style headers (svix-id,
             * svix-timestamp, svix-signature) and our validator reads all
             * three itself rather than relying on this single header name.
             */
            'signature_header_name' => 'svix-signature',

            /*
             * Verifies the svix HMAC before anything is stored. Invalid
             * signatures are rejected with nothing persisted.
             */
            'signature_validator' => ResendSignatureValidator::class,

            /*
             * Store and process every (validly signed) call. A profile
             * class could skip event types we don't care about; we only
             * subscribe to email.received in the Resend dashboard, so
             * filtering again here would be redundant.
             */
            'webhook_profile' => ProcessEverythingWebhookProfile::class,

            /*
             * This class determines the response on a valid webhook call.
             */
            'webhook_response' => DefaultRespondsTo::class,

            /*
             * The classname of the model to be used to store webhook calls. The class should
             * be equal or extend Spatie\WebhookClient\Models\WebhookCall.
             */
            'webhook_model' => WebhookCall::class,

            /*
             * svix-id uniquely identifies a delivery attempt — storing it
             * keeps a redelivery audit trail alongside the payload.
             */
            'store_headers' => [
                'svix-id',
            ],

            /*
             * Resend posts JSON, never multipart uploads — attachment
             * binaries are fetched from the Resend API during processing.
             */
            'store_attachments' => false,

            /*
             * The queued job that turns a stored webhook call into a
             * helpdesk request or timeline note.
             */
            'process_webhook_job' => ProcessInboundEmail::class,
        ],
    ],

    /*
     * The integer amount of days after which models should be deleted.
     *
     * It deletes all records after 30 days. Set to null if no models should be deleted.
     */
    'delete_after_days' => 30,

    /*
     * Should a unique token be added to the route name
     */
    'add_unique_token_to_route_name' => false,
];
