<?php

namespace App\Http\Resources;

use App\Models\Request as HelpdeskRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The API representation of a helpdesk request.
 *
 * The class collides with no framework type — but the underlying model
 * does: `App\Models\Request` shares a short name with `Illuminate\Http\
 * Request` (the resource's $request argument). The standing repo rule is
 * to alias the model (`as HelpdeskRequest`); the `@mixin` tag below points
 * editors and PHPStan at the model so `$this->access_key` etc. type-check.
 *
 * `access_key` is included on purpose: this is the create response, the one
 * moment a caller legitimately needs the key (it's how the Phase 4 portal
 * authenticates the customer to view the request). It is NOT exposed on any
 * listing or read endpoint.
 *
 * @mixin HelpdeskRequest
 */
class RequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'access_key' => $this->access_key,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
