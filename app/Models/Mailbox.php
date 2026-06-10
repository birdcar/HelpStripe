<?php

namespace App\Models;

use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An inbound email identity: `support@example.com` is a mailbox.
 *
 * Phase 3's email pipeline matches inbound mail to a mailbox by address
 * and files the new request under the mailbox's default category. The
 * category link is optional — a mailbox can leave triage to staff.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $address
 * @property int|null $category_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Category|null $category
 * @property-read Collection<int, Request> $requests
 */
#[Fillable(['team_id', 'name', 'address', 'category_id'])]
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    /**
     * Get the team (installation) this mailbox belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the default category new requests from this mailbox land in.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the requests that arrived through this mailbox.
     *
     * @return HasMany<Request, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }
}
