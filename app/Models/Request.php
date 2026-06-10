<?php

namespace App\Models;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Support\Resend\InboundEmail;
use Database\Factories\RequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
     * Get the timeline newest-first with authors eager-loaded — exactly
     * the shape the request detail page renders.
     *
     * A relation method can return a pre-constrained relation; callers
     * still get a HasMany they can refine further. The `id` tiebreak
     * keeps same-second notes (common in seeders and tests) stable.
     *
     * @return HasMany<Note, $this>
     */
    public function timeline(): HasMany
    {
        return $this->notes()
            ->with(['user', 'customer'])
            ->latest()
            ->latest('id');
    }

    /**
     * Scope to requests that have breached their category's first-response SLA.
     *
     * This scope is the *single source of truth* for "what counts as an SLA
     * breach." Phase 8's reports and Phase 6's automation conditions both call
     * it, so the dashboard's breach count and a rule that fires "when a request
     * breaches SLA" can never disagree — there is exactly one definition, here.
     *
     * A request breaches when EITHER:
     *  - it was answered, but the first response landed more than
     *    `sla_first_response_minutes` after it was created (answered late); OR
     *  - it is still unanswered and the target window has already elapsed
     *    (overdue — see scopeSlaOverdue, which this scope folds in).
     *
     * Requests with no category, or a category with no SLA target, can never
     * breach — there's nothing to measure against. The comparison uses `>`
     * (strictly greater), so a response landing exactly on the target minute
     * is in-SLA, not a breach.
     *
     * The minute-difference is computed in SQL via a driver-aware expression
     * (SQLite vs MySQL spell epoch conversion differently) so the scope works
     * as a real query — callers can `->slaBreached()->count()` without pulling
     * rows into PHP.
     *
     * @param  Builder<Request>  $query
     */
    #[Scope]
    protected function slaBreached(Builder $query): void
    {
        // whereHas runs a correlated subquery (`exists (select … from categories
        // where categories.id = requests.category_id and …)`). Inside it the
        // categories columns are in scope, and the outer requests columns are
        // reachable from raw SQL via the correlation — so the whole breach test
        // lives in one EXISTS clause, no manual join, no ambiguity.
        $query->whereHas('category', function (Builder $category) {
            $category
                ->whereNotNull('sla_first_response_minutes')
                ->where(function (Builder $clause) {
                    $clause
                        // Answered late: a first response exists but landed
                        // outside the target window.
                        ->where(function (Builder $late) {
                            $late->whereNotNull('requests.first_responded_at')
                                ->whereRaw($this->answeredLateSql());
                        })
                        // Overdue: still unanswered and the window has elapsed.
                        ->orWhere(function (Builder $open) {
                            $open->whereNull('requests.first_responded_at')
                                ->whereRaw($this->overdueSql(), [now()->getTimestamp()]);
                        });
                });
        });
    }

    /**
     * Scope to requests that are unanswered AND already past their SLA target.
     *
     * The "overdue" subset of a breach: no first response yet and the window
     * has elapsed. Exposed on its own because it's the actionable slice — work
     * these now to stop the breach. (scopeSlaBreached includes these plus the
     * already-answered-late requests, which are breaches you can't undo.)
     *
     * @param  Builder<Request>  $query
     */
    #[Scope]
    protected function slaOverdue(Builder $query): void
    {
        $query
            ->whereNull('first_responded_at')
            ->whereHas('category', function (Builder $category) {
                $category
                    ->whereNotNull('sla_first_response_minutes')
                    ->whereRaw($this->overdueSql(), [now()->getTimestamp()]);
            });
    }

    /**
     * SQL comparing the answered-in minutes against the category's target.
     *
     * SQLite and MySQL disagree on how to turn a datetime into epoch seconds
     * (`strftime('%s', …)` vs `UNIX_TIMESTAMP(…)`), so the whole comparison is
     * picked by a `match` on the connection's driver — each arm is a single
     * literal string, which is exactly what `whereRaw` wants. Dividing the
     * second-difference by 60 yields minutes; `>` (not `>=`) keeps a response
     * landing exactly on the target in-SLA. Running this in SQL means the
     * breach math never hydrates a row.
     *
     * Integer division truncates toward zero on both drivers, so the SLA is
     * enforced at whole-minute granularity: a response anywhere inside the
     * 61st minute of a 60-minute target still reads as 60 and stays in-SLA.
     * That matches `sla_first_response_minutes` being a minutes value — there
     * is no sub-minute SLA to enforce.
     *
     * @return literal-string
     */
    private function answeredLateSql(): string
    {
        return match ($this->getConnection()->getDriverName()) {
            'sqlite' => "(strftime('%s', requests.first_responded_at) - strftime('%s', requests.created_at)) / 60 > categories.sla_first_response_minutes",
            default => '(UNIX_TIMESTAMP(requests.first_responded_at) - UNIX_TIMESTAMP(requests.created_at)) / 60 > categories.sla_first_response_minutes',
        };
    }

    /**
     * SQL comparing the minutes-since-creation (measured to *now*) against the
     * category's target — the overdue test for unanswered requests.
     *
     * "Now" is a bound `?` parameter (the caller passes Carbon's `now()` epoch)
     * rather than the database's own clock, so frozen-time tests
     * (`CarbonImmutable::setTestNow(...)`) measure overdue against the same
     * instant the rest of the app sees. Same driver-branch as answeredLateSql.
     *
     * @return literal-string
     */
    private function overdueSql(): string
    {
        return match ($this->getConnection()->getDriverName()) {
            'sqlite' => "(? - strftime('%s', requests.created_at)) / 60 > categories.sla_first_response_minutes",
            default => '(? - UNIX_TIMESTAMP(requests.created_at)) / 60 > categories.sla_first_response_minutes',
        };
    }

    /**
     * Find the existing request an inbound email belongs to, if any.
     *
     * Matching strategy, strongest signal first:
     *
     *  1. Threading headers — the email's In-Reply-To/References ids are
     *     matched against `notes.message_id` (we store the Message-ID of
     *     every email-borne note, inbound and outbound). Mail clients
     *     preserve these automatically, so this catches normal replies.
     *  2. Subject token — the `[#id]` we put in every outbound subject.
     *     Survives clients that strip threading headers; scoped to the
     *     receiving mailbox's team so a pasted token can't land a note
     *     on another installation's request.
     *
     * Returns null when neither matches — the caller opens a new request.
     */
    public static function findForInbound(InboundEmail $email, ?Mailbox $mailbox = null): ?self
    {
        $referencedIds = $email->referencedMessageIds();

        if ($referencedIds !== []) {
            $note = Note::query()
                ->whereIn('message_id', $referencedIds)
                ->latest('id')
                ->first();

            if ($note !== null) {
                return $note->request;
            }
        }

        if (preg_match('/\[#(\d+)\]/', $email->subject, $matches) === 1) {
            return self::query()
                ->whereKey((int) $matches[1])
                ->when($mailbox !== null, fn ($query) => $query->where('team_id', $mailbox->team_id))
                ->first();
        }

        return null;
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
