<?php

namespace App\Support\Resend;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * Verifies Resend's webhook signatures.
 *
 * Resend signs webhooks with the svix scheme: three headers (svix-id,
 * svix-timestamp, svix-signature) and a shared secret prefixed `whsec_`.
 * The signature is an HMAC-SHA256 over the literal string
 * "{id}.{timestamp}.{raw body}" keyed with the BASE64-DECODED secret —
 * two classic implementation traps live here:
 *
 *  1. The raw request body must be used, byte for byte. Re-encoding
 *     parsed JSON produces a different string and a failed match.
 *  2. The secret's `whsec_` prefix is not part of the key; the remainder
 *     is base64 that must be decoded before keying the HMAC.
 *
 * The timestamp check bounds replay attacks: a captured webhook can only
 * be re-sent within the tolerance window. hash_equals() compares in
 * constant time, closing the timing side-channel string comparison
 * would open.
 */
class ResendSignatureValidator implements SignatureValidator
{
    /**
     * How far the svix-timestamp may drift from our clock (seconds).
     */
    private const int TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Determine if the request's signature is valid.
     */
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // The package types signingSecret as a non-null string, defaulting
        // to '' when RESEND_WEBHOOK_SECRET is unset — so an empty string is
        // the "not configured" case, and we refuse to validate against it.
        $secret = $config->signingSecret;

        if ($secret === '') {
            return false;
        }

        $messageId = $request->header('svix-id');
        $timestamp = $request->header('svix-timestamp');
        $signatureHeader = $request->header('svix-signature');

        if ($messageId === null || $timestamp === null || $signatureHeader === null) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $key = base64_decode(Str::after($secret, 'whsec_'), true);

        if ($key === false) {
            return false;
        }

        $signedContent = sprintf('%s.%s.%s', $messageId, $timestamp, $request->getContent());
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        // The header may carry several space-separated "v1,<base64>"
        // entries (svix rotates secrets without dropping deliveries);
        // any one matching signature authenticates the call.
        foreach (explode(' ', $signatureHeader) as $versionedSignature) {
            [$version, $signature] = array_pad(explode(',', $versionedSignature, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
