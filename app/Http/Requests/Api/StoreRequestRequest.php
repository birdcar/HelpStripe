<?php

namespace App\Http\Requests\Api;

use App\Models\Category;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the payload for POST /api/v1/requests (the third intake channel).
 *
 * Expected body:
 *
 *     { "subject": "...", "body": "...",
 *       "customer": { "name": "...", "email": "..." },
 *       "category_id": 2 }   // optional
 *
 * The `category_id` rule scopes the exists check to the installation's
 * team, so a caller can't file a request under another tenant's category
 * by guessing ids.
 */
class StoreRequestRequest extends FormRequest
{
    /**
     * Authorize the request.
     *
     * Authentication is the AuthenticateApiToken middleware's job; by the
     * time validation runs the bearer token is already verified, so there's
     * no per-user authorization left to do here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'email', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists(Category::class, 'id')->where('team_id', $this->installationTeam()?->id),
            ],
        ];
    }

    /**
     * The single team this installation represents (see DemoSeeder).
     *
     * The static-token API has no tenant context of its own, so requests
     * land on the installation's team — the same fallback the inbound email
     * pipeline uses when an address doesn't map to a mailbox.
     */
    public function installationTeam(): ?Team
    {
        return Team::query()->orderBy('id')->first();
    }
}
