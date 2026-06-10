<?php

namespace App\Models;

use Database\Factories\FilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved Filter — HelpSpot's name for a saved queue view.
 *
 * A Filter stores the queue's criteria array verbatim as JSON. Applying
 * one simply feeds those criteria back through App\Queries\RequestQueue,
 * the same code path the filter bar uses — so a Filter can never drift
 * from what the queue itself can express.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $name
 * @property bool $is_shared
 * @property array<string, mixed> $criteria
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 */
#[Fillable(['team_id', 'user_id', 'name', 'is_shared', 'criteria'])]
class Filter extends Model
{
    /** @use HasFactory<FilterFactory> */
    use HasFactory;

    /**
     * Get the team (installation) this filter belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the staff member who saved this filter.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * The `array` cast JSON-encodes on write and decodes on read, so PHP
     * code only ever sees a criteria array — never a JSON string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'criteria' => 'array',
        ];
    }
}
