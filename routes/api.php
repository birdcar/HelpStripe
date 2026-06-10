<?php

use App\Http\Controllers\Api\V1\RequestController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — the third intake channel
|--------------------------------------------------------------------------
|
| Registered via bootstrap/app.php's `api:` parameter, which applies the
| `api` middleware group (stateless — no sessions, no CSRF) and prefixes
| everything with /api. The /v1 prefix is deliberate: versioning the URL
| from day one means a future /v2 can change the contract without
| breaking integrations already pointed at /v1.
|
*/

Route::prefix('v1')->middleware(AuthenticateApiToken::class)->group(function () {
    Route::post('requests', [RequestController::class, 'store'])->name('api.v1.requests.store');
});
