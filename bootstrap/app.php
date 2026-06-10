<?php

use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Exceptions\InvalidWebhookSignature;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetTeamUrlDefaults::class,
        ]);

        // Webhook senders POST from their own servers — they have no CSRF
        // token and never will. Exempting the path is the standard move
        // for machine-to-machine endpoints; authenticity is enforced by
        // the svix signature check instead (a far stronger guarantee).
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // webhook-client throws a bare exception on a bad signature,
        // which would render as a 500. It's really an authentication
        // failure: answer 401 so Resend's delivery log shows "rejected",
        // not "your endpoint is broken" (5xx also triggers retries —
        // pointless for a request that can never validate).
        $exceptions->render(fn (InvalidWebhookSignature $e) => response()->json([
            'message' => 'Invalid webhook signature.',
        ], 401));
    })->create();
