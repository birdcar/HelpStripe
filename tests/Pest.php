<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Load a recorded Resend fixture (tests/Fixtures/resend/{name}.json).
 *
 * Each fixture bundles the webhook POST body (`webhook`), the email
 * content the job fetches from the Resend API (`email`), and the
 * attachment listing (`attachments`) — see the _comment key inside.
 *
 * @return array{webhook: array<string, mixed>, email: array<string, mixed>, attachments: array<string, mixed>}
 */
function resendFixture(string $name): array
{
    $path = base_path("tests/Fixtures/resend/{$name}.json");

    return json_decode((string) file_get_contents($path), associative: true);
}
