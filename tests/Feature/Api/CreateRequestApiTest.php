<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Request;
use App\Models\Team;

/*
 * POST /api/v1/requests — the JSON intake channel. The middleware checks a
 * static bearer token from config('helpstripe.api_token'); these tests set
 * that token, then exercise the auth gate, validation, and the happy path
 * (which must reuse CreateRequest so the request shows up in the queue with
 * an "api" source).
 */

beforeEach(function () {
    config(['helpstripe.api_token' => 'test-secret-token']);
    $this->team = Team::factory()->create();
});

/**
 * @param  array<string, string>  $headers
 */
function postRequest(array $payload, array $headers = ['Authorization' => 'Bearer test-secret-token'])
{
    return test()->postJson(route('api.v1.requests.store'), $payload, $headers);
}

function validApiPayload(): array
{
    return [
        'subject' => "Can't log in",
        'body' => 'Help!',
        'customer' => ['name' => 'Pat Customer', 'email' => 'pat@example.com'],
    ];
}

test('a request without a bearer token is rejected with 401', function () {
    postRequest(validApiPayload(), headers: [])
        ->assertUnauthorized();

    expect(Request::query()->count())->toBe(0);
});

test('a request with the wrong bearer token is rejected with 401', function () {
    postRequest(validApiPayload(), ['Authorization' => 'Bearer the-wrong-token'])
        ->assertUnauthorized();

    expect(Request::query()->count())->toBe(0);
});

test('a malformed payload is rejected with 422 and field errors', function () {
    postRequest(['subject' => '', 'customer' => ['email' => 'not-an-email']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subject', 'body', 'customer.name', 'customer.email']);

    expect(Request::query()->count())->toBe(0);
});

test('a valid payload creates a request and returns 201 with the access key', function () {
    $response = postRequest(validApiPayload())->assertCreated();

    $request = Request::query()->latest('id')->firstOrFail();

    $response->assertJson([
        'data' => [
            'id' => $request->id,
            'subject' => "Can't log in",
            'status' => 'active',
            'source' => 'api',
            'access_key' => $request->access_key,
        ],
    ]);

    expect($request->source->value)->toBe('api')
        ->and($request->team_id)->toBe($this->team->id)
        ->and($request->notes()->first()->body)->toBe('Help!');
});

test('the created request reuses an existing customer matched case-insensitively', function () {
    $existing = Customer::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pat@example.com',
    ]);

    $payload = validApiPayload();
    $payload['customer']['email'] = 'PAT@EXAMPLE.COM';

    postRequest($payload)->assertCreated();

    expect(Customer::query()->where('team_id', $this->team->id)->count())->toBe(1)
        ->and(Request::query()->latest('id')->firstOrFail()->customer_id)->toBe($existing->id);
});

test('a category from another team cannot be assigned through the api', function () {
    $foreignCategory = Category::factory()->create(); // its own team

    $payload = validApiPayload();
    $payload['category_id'] = $foreignCategory->id;

    postRequest($payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

test('a category belonging to the installation team is accepted', function () {
    $category = Category::factory()->create(['team_id' => $this->team->id]);

    $payload = validApiPayload();
    $payload['category_id'] = $category->id;

    postRequest($payload)->assertCreated();

    expect(Request::query()->latest('id')->firstOrFail()->category_id)->toBe($category->id);
});
