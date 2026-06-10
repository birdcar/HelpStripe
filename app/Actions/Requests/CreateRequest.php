<?php

namespace App\Actions\Requests;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Events\RequestCreated;
use App\Models\Customer;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create a helpdesk request plus its opening customer message.
 *
 * This is the single write-path for new requests: the agent UI calls it
 * now, the email pipeline (Phase 3), portal (Phase 4), and API (Phase 3)
 * call it later. Centralizing it means RequestCreated fires for every
 * channel without each channel remembering to do so.
 */
class CreateRequest
{
    /**
     * Open a new request for the customer with their initial message.
     *
     * @param  array{category_id?: int|null, mailbox_id?: int|null, assigned_to?: int|null, is_urgent?: bool}  $attributes
     */
    public function handle(
        Customer $customer,
        string $subject,
        string $body,
        RequestSource $source,
        array $attributes = [],
    ): Request {
        $request = DB::transaction(function () use ($customer, $subject, $body, $source, $attributes) {
            $request = Request::create([
                'team_id' => $customer->team_id,
                'customer_id' => $customer->id,
                'category_id' => $attributes['category_id'] ?? null,
                'mailbox_id' => $attributes['mailbox_id'] ?? null,
                'assigned_to' => $attributes['assigned_to'] ?? null,
                'subject' => $subject,
                // Set explicitly rather than leaning on the column default:
                // the default only applies at INSERT time, so the in-memory
                // model would read `status: null` until refreshed.
                'status' => RequestStatus::Active,
                'source' => $source,
                'is_urgent' => $attributes['is_urgent'] ?? false,
            ]);

            // The opening message is always the customer's words — even
            // when an agent files the request on their behalf (phone call,
            // walk-up), the timeline starts with what the customer asked.
            $request->notes()->create([
                'customer_id' => $customer->id,
                'body' => $body,
                'is_private' => false,
                'source' => $source,
            ]);

            return $request;
        });

        // Fired after the transaction commits so listeners never observe
        // a request that could still roll back.
        RequestCreated::dispatch($request);

        return $request;
    }
}
