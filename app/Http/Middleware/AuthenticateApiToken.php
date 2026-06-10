<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate the JSON intake API with a single static bearer token.
 *
 * This is deliberately the simplest thing that teaches the mechanics:
 * read `Authorization: Bearer {token}`, then compare it in constant time
 * to config('helpstripe.api_token'). Laravel Sanctum is the production-
 * grade replacement — per-client tokens, abilities, revocation — named as
 * such in the tour doc's Future Considerations.
 *
 * `hash_equals` is used instead of `===` so the check takes the same time
 * regardless of how many leading characters match: a plain `===` short-
 * circuits on the first wrong byte, leaking the token a byte at a time to
 * an attacker timing the responses.
 */
class AuthenticateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('helpstripe.api_token');
        $provided = $request->bearerToken();

        if (! is_string($configured) || $configured === '' || ! is_string($provided) || ! hash_equals($configured, $provided)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
