<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Requests\CreateRequest;
use App\Enums\RequestSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreRequestRequest;
use App\Http\Resources\RequestResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The JSON intake channel — the third way a request enters the helpdesk
 * (after email and the agent UI), reusing the same CreateRequest action so
 * RequestCreated fires and first_responded_at is owned in one place.
 */
class RequestController extends Controller
{
    public function __construct(private readonly CreateRequest $createRequest) {}

    /**
     * Store a new request from an authenticated API caller.
     *
     * Validation (including the team-scoped category check) has already run
     * in StoreRequestRequest by the time this method is entered.
     */
    public function store(StoreRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // The API has no tenant context of its own — a request lands on the
        // installation's team, the same fallback the inbound email pipeline
        // uses. A configured-and-seeded install always has one; abort_unless
        // turns the theoretical "no team at all" state into a clean 503
        // instead of a null dereference.
        $team = $request->installationTeam();
        abort_unless($team !== null, 503, 'No installation team is configured.');

        $customer = $this->resolveCustomer(
            $team->id,
            $validated['customer']['email'],
            $validated['customer']['name'],
        );

        $helpdeskRequest = $this->createRequest->handle(
            $customer,
            $validated['subject'],
            $validated['body'],
            RequestSource::Api,
            ['category_id' => $validated['category_id'] ?? null],
        );

        // 201 Created, wrapped in the standard `data` envelope by the
        // resource. The access_key rides along so the caller can hand it to
        // the customer for portal access.
        return RequestResource::make($helpdeskRequest)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Find the customer by email (case-insensitively) or create them.
     *
     * Mirrors the inbound email pipeline: `Pat@Example.com` and
     * `pat@example.com` are the same person, so the lookup lowercases both
     * sides — a duplicate customer would fork their request history.
     */
    private function resolveCustomer(int $teamId, string $email, string $name): Customer
    {
        $normalizedEmail = Str::lower($email);

        $existing = Customer::query()
            ->where('team_id', $teamId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        return $existing ?? Customer::create([
            'team_id' => $teamId,
            'name' => $name,
            'email' => $normalizedEmail,
        ]);
    }
}
