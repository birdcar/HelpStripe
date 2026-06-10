<?php

namespace App\Models;

use App\Enums\RequestSource;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A single entry on a request's timeline: a public reply or a private
 * internal note, authored by a staff member OR a customer.
 *
 * Authorship invariant: exactly one of `user_id` (staff) / `customer_id`
 * (customer) is set. This uses two nullable foreign keys instead of a
 * polymorphic `author` relation — simpler to teach and to query, at the
 * cost of the database not enforcing the XOR itself (no CHECK constraint;
 * factories and actions uphold it).
 *
 * @property int $id
 * @property int $request_id
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property string $body
 * @property bool $is_private
 * @property RequestSource $source
 * @property string|null $message_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Request $request
 * @property-read User|null $user
 * @property-read Customer|null $customer
 */
#[Fillable(['request_id', 'user_id', 'customer_id', 'body', 'is_private', 'source', 'message_id'])]
class Note extends Model implements HasMedia
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * Register the media collections for this model.
     *
     * Email attachments hang off the NOTE they arrived with, not the
     * request — an attachment belongs to a specific message on the
     * timeline, exactly like in a mail client. spatie/laravel-medialibrary
     * handles storage, file naming, and the polymorphic `media` table;
     * this model only declares the collection name.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * Get the helpdesk request this note belongs to.
     *
     * Note the namespace collision lesson in action: inside App\Models the
     * bare `Request::class` resolves to our model, not the HTTP request.
     *
     * @return BelongsTo<Request, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /**
     * Get the staff author, when this note was written by staff.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the customer author, when this note is a customer reply.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Determine whether a customer wrote this note.
     */
    public function isFromCustomer(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'source' => RequestSource::class,
        ];
    }
}
