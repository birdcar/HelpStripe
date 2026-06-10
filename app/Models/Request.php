<?php

namespace App\Models;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use Database\Factories\RequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Tags\HasTags;

/**
 * A helpdesk request — HelpSpot's word for a ticket.
 *
 * Deliberate teaching decision: this class is named `Request`, colliding
 * with `Illuminate\Http\Request` exactly the way HelpSpot's own domain
 * vocabulary does. Inside `App\Models` the bare name resolves here; any
 * file needing both classes aliases the framework one:
 *
 *     use Illuminate\Http\Request as HttpRequest;
 *
 * See docs/tour/01-foundation.md for the full namespaces lesson.
 *
 * @property int $id
 * @property int $team_id
 * @property int $customer_id
 * @property int|null $category_id
 * @property int|null $mailbox_id
 * @property int|null $assigned_to
 * @property string $subject
 * @property RequestStatus $status
 * @property RequestSource $source
 * @property bool $is_urgent
 * @property string $access_key
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Customer $customer
 * @property-read Category|null $category
 * @property-read Mailbox|null $mailbox
 * @property-read User|null $assignee
 * @property-read Collection<int, Note> $notes
 */
#[Fillable([
    'team_id',
    'customer_id',
    'category_id',
    'mailbox_id',
    'assigned_to',
    'subject',
    'status',
    'source',
    'is_urgent',
    'first_responded_at',
    'resolved_at',
])]
class Request extends Model
{
    /**
     * Three traits, three lessons:
     *  - HasFactory wires the model to RequestFactory for tests/seeding.
     *  - HasTags (spatie/laravel-tags) adds a polymorphic `tags` relation —
     *    `$request->attachTag('vip')` with zero schema work of our own.
     *  - LogsActivity (spatie/laravel-activitylog) records changes to the
     *    attributes listed in getActivitylogOptions() — this IS the
     *    request history feature.
     *
     * @use HasFactory<RequestFactory>
     */
    use HasFactory, HasTags, LogsActivity;

    /**
     * Bootstrap the model and its traits.
     *
     * Model events are Eloquent's lifecycle hooks. `creating` fires just
     * before the INSERT, which makes it the right place to derive values
     * the caller shouldn't have to provide — here, the portal access key.
     * (Compare Team::boot(), which derives slugs the same way.)
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Request $request) {
            if (empty($request->access_key)) {
                $request->access_key = Str::random(12);
            }
        });
    }

    /**
     * Configure which attribute changes the activity log records.
     *
     * logOnly() limits history to the fields that matter on a ticket
     * timeline; logOnlyDirty() records only attributes that actually
     * changed, so each activity row reads as a focused diff (old → new).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'category_id', 'is_urgent'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the team (installation) this request belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the customer who opened this request.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the category this request is filed under, if any.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the mailbox this request arrived through, if it came by email.
     *
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * Get the staff member this request is assigned to, if anyone.
     *
     * The column is `assigned_to`, not `user_id`, so the foreign key is
     * passed explicitly — Eloquent can only guess keys that follow the
     * `{relation}_id` naming convention.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the timeline of public replies and private notes, oldest first.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Enum casts are the payoff for backed enums: reads hydrate the raw
     * string into a RequestStatus/RequestSource instance, writes accept
     * either the enum or its value — round-tripping is automatic.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'source' => RequestSource::class,
            'is_urgent' => 'boolean',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
